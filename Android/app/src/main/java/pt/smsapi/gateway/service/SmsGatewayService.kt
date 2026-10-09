package pt.smsapi.gateway.service

import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.withTimeoutOrNull
import pt.smsapi.gateway.R
import pt.smsapi.gateway.data.Level
import pt.smsapi.gateway.data.LocalDb
import pt.smsapi.gateway.data.Settings
import pt.smsapi.gateway.data.Sims
import pt.smsapi.gateway.net.ApiClient
import pt.smsapi.gateway.net.ApiException
import pt.smsapi.gateway.net.QueuedSms
import pt.smsapi.gateway.sms.SmsResult
import pt.smsapi.gateway.sms.SmsSender

class SmsGatewayService : Service() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val cycleLock = Mutex()
    private val sender by lazy { SmsSender(this) }

    private var loopJob: Job? = null
    private var wakeLock: PowerManager.WakeLock? = null
    private lateinit var db: LocalDb

    override fun onCreate() {
        super.onCreate()
        db = LocalDb(this)
        Notifications.ensureChannels(this)
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopEverything()
            return START_NOT_STICKY
        }

        startForegroundWith(getString(R.string.notif_text_ready))
        GatewayState.update { it.copy(running = true, phase = GatewayState.Phase.STARTING) }

        if (loopJob?.isActive != true) {
            loopJob = scope.launch { loop() }
        }
        if (intent?.action == ACTION_POLL_NOW) {
            scope.launch { runCycle() }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        loopJob?.cancel()
        wakeLock?.let { if (it.isHeld) it.release() }
        wakeLock = null
        GatewayState.update { GatewayState.Status() }
        scope.cancel()
        super.onDestroy()
    }

    // ------------------------------------------------------------------- loop

    private suspend fun loop() {
        while (currentCoroutineContext().isActive) {
            runCycle()

            val waitMs = Settings.intervalSecs(this@SmsGatewayService).toLong() * 1000L
            GatewayState.update { it.copy(nextPollAt = System.currentTimeMillis() + waitMs) }
            delay(waitMs)
        }
    }

    private suspend fun runCycle() {
        if (!cycleLock.tryLock()) return

        try {
            acquireWakeLock()

            val server = Settings.serverUrl(this)
            val apiKey = Settings.apiKey(this)

            if (server.isBlank() || apiKey.isNullOrBlank()) {
                GatewayState.setError(getString(R.string.msg_no_server_configured))
                return
            }
            if (!hasInternet()) {
                GatewayState.setError(getString(R.string.msg_no_internet))
                return
            }

            val simOptions = Sims.active(this)
            val subscriptionId = Settings.subscriptionId(this)
            val simLabel = Sims.labelFor(simOptions, subscriptionId)

            var token = Settings.deviceToken(this)
            if (token.isNullOrBlank()) {
                GatewayState.setPhase(GatewayState.Phase.POLLING)
                val response = ApiClient(server, apiKey, null).register(
                    deviceName = Settings.deviceName(this),
                    installId = Settings.installId(this),
                    manufacturer = Build.MANUFACTURER ?: "Android",
                    model = Build.MODEL ?: "device",
                    osVersion = Build.VERSION.RELEASE ?: "?",
                    simLabel = simLabel
                )
                token = response.getString("device_token")
                Settings.setDeviceToken(this, token)
                applyServerOverrides(response)
                GatewayState.update { it.copy(registered = true) }
                db.add(Level.INFO, getString(R.string.log_registered))
            }

            val client = ApiClient(server, apiKey, token)

            GatewayState.setPhase(GatewayState.Phase.POLLING)
            val heartbeat = client.heartbeat(simLabel)
            applyServerOverrides(heartbeat)
            val pendingBefore = heartbeat.optInt("pending", 0)

            val queue = client.poll(Settings.maxPerPoll(this))
            val acknowledged = ArrayList<Long>(queue.size)

            for (message in queue) {
                GatewayState.setPhase(GatewayState.Phase.SENDING)
                val result = dispatch(message, subscriptionId)
                acknowledged += handleResult(client, message, result, simLabel)
            }
            if (acknowledged.isNotEmpty()) {
                client.ack(acknowledged)
            }

            val remaining = (pendingBefore - queue.size).coerceAtLeast(0)
            GatewayState.update {
                it.copy(
                    phase = GatewayState.Phase.OK,
                    lastPollAt = System.currentTimeMillis(),
                    lastError = null,
                    pending = remaining,
                    sentThisCycle = acknowledged.size,
                    totalSent = it.totalSent + acknowledged.size,
                    registered = true
                )
            }
            updateNotification(getString(R.string.notif_text_ready))
        } catch (e: ApiException) {
            if (e.code == "device_revoked" || e.code == "device_not_found") {
                Settings.clearDeviceToken(this)
                db.add(Level.WARN, getString(R.string.log_token_rejected))
            }
            GatewayState.setError("${e.code}: ${e.message}")
            db.add(Level.ERROR, "API ${e.code}: ${e.message}")
        } catch (e: Exception) {
            val reason = e.message ?: e.javaClass.simpleName
            GatewayState.setError(reason)
            db.add(Level.ERROR, reason)
        } finally {
            wakeLock?.let { if (it.isHeld) it.release() }
            cycleLock.unlock()
        }
    }

    /**
     * The administrator can pin the polling interval and batch size from the web
     * panel; a value of 0 means "keep whatever the app is configured with".
     */
    private fun applyServerOverrides(response: org.json.JSONObject) {
        val interval = response.optInt("poll_interval", 0)
        if (interval in Settings.MIN_INTERVAL..Settings.MAX_INTERVAL) {
            Settings.setIntervalSecs(this, interval)
        }
        val maxPerPoll = response.optInt("max_per_poll", 0)
        if (maxPerPoll in 1..100) {
            Settings.setMaxPerPoll(this, maxPerPoll)
        }
    }

    private suspend fun dispatch(message: QueuedSms, subscriptionId: Int): SmsResult? =
        withTimeoutOrNull(SEND_TIMEOUT_MS) { sender.send(message.phone, message.body, subscriptionId) }

    private suspend fun handleResult(
        client: ApiClient,
        message: QueuedSms,
        result: SmsResult?,
        simLabel: String
    ): Long {
        if (result == null) {
            val reason = getString(R.string.log_send_timeout)
            runCatching { client.fail(message.id, reason) }
            db.add(Level.ERROR, reason, message.phone, message.body, message.id)
            Notifications.alert(this, getString(R.string.notif_title), reason)
            return 0L
        }

        if (result.ok) {
            db.add(
                Level.SEND,
                getString(R.string.log_send_ok, result.parts, simLabel),
                message.phone,
                message.body,
                message.id
            )
            if (Settings.notifySent(this)) {
                Notifications.alert(
                    this,
                    getString(R.string.notif_sent, message.phone),
                    "${getString(R.string.log_sim_used, simLabel)} · ${getString(R.string.log_parts, result.parts)}\n${message.body}"
                )
            }
            return message.id
        }

        val reason = result.error ?: getString(R.string.log_send_failed_unknown)
        runCatching { client.fail(message.id, reason) }
        db.add(Level.ERROR, reason, message.phone, message.body, message.id)
        Notifications.alert(this, getString(R.string.notif_failed, message.phone), reason)
        return 0L
    }

    // ------------------------------------------------------------------ infra

    private fun stopEverything() {
        loopJob?.cancel()
        stopForeground(STOP_FOREGROUND_REMOVE)
        GatewayState.update { GatewayState.Status() }
        stopSelf()
    }

    private fun startForegroundWith(text: String) {
        val notification = Notifications.status(this, text)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                Notifications.NOTIF_STATUS_ID,
                notification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
            )
        } else {
            startForeground(Notifications.NOTIF_STATUS_ID, notification)
        }
    }

    private fun updateNotification(text: String) {
        val manager = getSystemService(android.app.NotificationManager::class.java) ?: return
        runCatching {
            manager.notify(
                Notifications.NOTIF_STATUS_ID,
                Notifications.status(this, text)
            )
        }
    }

    private fun hasInternet(): Boolean {
        val manager = getSystemService(ConnectivityManager::class.java) ?: return true
        val network = manager.activeNetwork ?: return false
        val capabilities = manager.getNetworkCapabilities(network) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    @Suppress("DEPRECATION")
    private fun acquireWakeLock() {
        if (wakeLock?.isHeld == true) return
        val power = getSystemService(PowerManager::class.java) ?: return
        wakeLock = try {
            power.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKE_LOCK_TAG).apply {
                setReferenceCounted(false)
                acquire(WAKE_LOCK_MS)
            }
        } catch (e: Exception) {
            null
        }
    }

    fun isIgnoringBatteryOptimisations(): Boolean {
        val power = getSystemService(PowerManager::class.java) ?: return true
        return power.isIgnoringBatteryOptimizations(packageName)
    }

    companion object {
        const val ACTION_STOP = "pt.smsapi.gateway.action.STOP"
        const val ACTION_POLL_NOW = "pt.smsapi.gateway.action.POLL_NOW"

        private const val SEND_TIMEOUT_MS = 90_000L
        private const val WAKE_LOCK_MS = 120_000L
        private const val WAKE_LOCK_TAG = "smsapi:gateway:cycle"

        fun start(context: Context) {
            ContextCompat.startForegroundService(
                context,
                Intent(context, SmsGatewayService::class.java)
            )
        }

        fun stop(context: Context) {
            context.startService(
                Intent(context, SmsGatewayService::class.java).setAction(ACTION_STOP)
            )
        }

        fun syncNow(context: Context) {
            context.startService(
                Intent(context, SmsGatewayService::class.java).setAction(ACTION_POLL_NOW)
            )
        }

        @Suppress("unused")
        fun batterySettingsIntent(): Intent =
            Intent(android.provider.Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
    }
}
package pt.smsapi.gateway.data

import android.content.Context
import android.os.Build
import pt.smsapi.gateway.crypto.SecureStore

object Settings {

    private const val PREFS = "smsapi_settings"

    private const val K_SERVER = "server_url"
    private const val K_DEVICE = "device_name"
    private const val K_INTERVAL = "poll_interval"
    private const val K_MAX = "max_per_poll"
    private const val K_AUTOSTART = "auto_start"
    private const val K_NOTIFY_SENT = "notify_sent"
    private const val K_INSTALL_ID = "install_id"
    private const val K_SUBSCRIPTION = "subscription_id"
    private const val K_LOG_COLLAPSED = "log_collapsed"
    private const val K_LANGUAGE = "ui_language"

    const val K_API_KEY = "api_key"
    const val K_DEVICE_TOKEN = "device_token"

    const val MIN_INTERVAL = 15
    const val MAX_INTERVAL = 86_400
    const val DEFAULT_INTERVAL = 60
    const val DEFAULT_MAX_PER_POLL = 5

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    // ---------------------------------------------------------------- server

    fun serverUrl(context: Context): String = prefs(context).getString(K_SERVER, "") ?: ""

    fun setServerUrl(context: Context, value: String) {
        prefs(context).edit().putString(K_SERVER, value.trim().trimEnd('/')).apply()
    }

    fun deviceName(context: Context): String =
        prefs(context).getString(K_DEVICE, "") ?: defaultDeviceName()

    fun setDeviceName(context: Context, value: String) {
        prefs(context).edit().putString(K_DEVICE, value.trim()).apply()
    }

    fun defaultDeviceName(): String {
        val model = Build.MODEL?.trim().orEmpty().ifEmpty { "Android" }
        return "Android - $model"
    }

    // ---------------------------------------------------------------- tuning

    fun intervalSecs(context: Context): Int =
        prefs(context).getInt(K_INTERVAL, DEFAULT_INTERVAL).coerceIn(MIN_INTERVAL, MAX_INTERVAL)

    fun setIntervalSecs(context: Context, value: Int) {
        prefs(context).edit()
            .putInt(K_INTERVAL, value.coerceIn(MIN_INTERVAL, MAX_INTERVAL))
            .apply()
    }

    fun maxPerPoll(context: Context): Int =
        prefs(context).getInt(K_MAX, DEFAULT_MAX_PER_POLL).coerceIn(1, 100)

    fun setMaxPerPoll(context: Context, value: Int) {
        prefs(context).edit().putInt(K_MAX, value.coerceIn(1, 100)).apply()
    }

    /**
     * Subscription used to send SMS. [Sims.AUTO_SUBSCRIPTION_ID] follows the
     * system default; otherwise it is a real Android subscription id.
     */
    fun subscriptionId(context: Context): Int =
        prefs(context).getInt(K_SUBSCRIPTION, Sims.AUTO_SUBSCRIPTION_ID)

    fun setSubscriptionId(context: Context, value: Int) {
        prefs(context).edit().putInt(K_SUBSCRIPTION, value).apply()
    }

    fun autoStart(context: Context): Boolean = prefs(context).getBoolean(K_AUTOSTART, true)

    fun setAutoStart(context: Context, value: Boolean) {
        prefs(context).edit().putBoolean(K_AUTOSTART, value).apply()
    }

    fun notifySent(context: Context): Boolean = prefs(context).getBoolean(K_NOTIFY_SENT, false)

    fun setNotifySent(context: Context, value: Boolean) {
        prefs(context).edit().putBoolean(K_NOTIFY_SENT, value).apply()
    }

    fun logCollapsed(context: Context): Boolean = prefs(context).getBoolean(K_LOG_COLLAPSED, false)

    fun setLogCollapsed(context: Context, value: Boolean) {
        prefs(context).edit().putBoolean(K_LOG_COLLAPSED, value).apply()
    }

    /**
     * UI language as a BCP-47 tag. An empty string means "follow the phone's
     * language", which is what most people want.
     */
    fun language(context: Context): String = prefs(context).getString(K_LANGUAGE, "") ?: ""

    fun setLanguage(context: Context, value: String) {
        prefs(context).edit().putString(K_LANGUAGE, value.trim()).apply()
    }

    /** Stable random identifier for this installation, used to re-link a device. */
    fun installId(context: Context): String {
        prefs(context).getString(K_INSTALL_ID, null)?.let { return it }
        val generated = java.util.UUID.randomUUID().toString().replace("-", "")
        prefs(context).edit().putString(K_INSTALL_ID, generated).apply()
        return generated
    }

    // --------------------------------------------------------------- secrets

    fun apiKey(context: Context): String? = SecureStore.get(context, K_API_KEY)

    fun setApiKey(context: Context, value: String) = SecureStore.put(context, K_API_KEY, value.trim())

    fun deviceToken(context: Context): String? = SecureStore.get(context, K_DEVICE_TOKEN)

    fun setDeviceToken(context: Context, value: String) =
        SecureStore.put(context, K_DEVICE_TOKEN, value.trim())

    fun clearDeviceToken(context: Context) = SecureStore.remove(context, K_DEVICE_TOKEN)

    // ----------------------------------------------------------------- state

    fun isConfigured(context: Context): Boolean =
        serverUrl(context).isNotBlank() && !apiKey(context).isNullOrBlank()
}
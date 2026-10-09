package pt.smsapi.gateway.ui

import android.Manifest
import android.app.role.RoleManager
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.res.ColorStateList
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.view.View
import android.widget.LinearLayout
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import androidx.recyclerview.widget.LinearLayoutManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import pt.smsapi.gateway.R
import pt.smsapi.gateway.data.Level
import pt.smsapi.gateway.data.LocalDb
import pt.smsapi.gateway.data.Settings
import pt.smsapi.gateway.data.SimOption
import pt.smsapi.gateway.data.Sims
import pt.smsapi.gateway.data.UiLanguage
import pt.smsapi.gateway.databinding.ActivityMainBinding
import pt.smsapi.gateway.net.ApiClient
import pt.smsapi.gateway.service.GatewayState
import pt.smsapi.gateway.service.SmsGatewayService
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var db: LocalDb
    private val adapter = LogAdapter()
    private val clock = SimpleDateFormat("dd/MM HH:mm:ss", Locale.getDefault())

    private var simOptions: List<SimOption> = emptyList()
    private var simLabels: List<String> = emptyList()
    private var simIds: List<Int> = emptyList()
    private var pendingLanguage: String = ""

    private enum class PermAction { GRANT, PHONE, BATTERY, SMS_ROLE, NONE }

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {
        refreshSims()
        updatePermissionCard()
    }

    private val roleLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { updatePermissionCard() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        db = LocalDb(this)

        binding.listLog.layoutManager = LinearLayoutManager(this)
        binding.listLog.adapter = adapter
        binding.listLog.itemAnimator = null

        binding.btnSaveRegister.setOnClickListener { persist(register = true) }
        binding.btnSaveOnly.setOnClickListener { persist(register = false) }
        binding.btnToggleService.setOnClickListener { toggleService() }
        binding.btnSync.setOnClickListener { SmsGatewayService.syncNow(this) }
        binding.btnPing.setOnClickListener { ping() }
        binding.btnSettings.setOnClickListener { showSetup(true) }
        binding.btnRevoke.setOnClickListener { confirmRevoke() }
        binding.btnClearLog.setOnClickListener { confirmClearLog() }
        binding.btnPermAction.setOnClickListener { onPermAction() }
        binding.valToken.setOnLongClickListener { copyToken(); true }
        binding.logHeader.setOnClickListener { toggleLog() }

        loadConfigIntoForm()
        setupLanguagePicker()
        refreshSims()
        applyLogCollapsed(Settings.logCollapsed(this), save = false)
        showSetup(!Settings.isConfigured(this))
        refreshLog()
        requestMissingPermissions()

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                launch { GatewayState.status.collect { render(it) } }
                launch { LocalDb.changes.collect { refreshLog() } }
                launch {
                    while (isActive) {
                        render(GatewayState.status.value)
                        delay(1_000)
                    }
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        refreshSims()
        updatePermissionCard()
        refreshLog()
    }

    // ------------------------------------------------------------------ setup

    private fun loadConfigIntoForm() {
        binding.inputServerUrl.setText(Settings.serverUrl(this))
        binding.inputApiKey.setText(Settings.apiKey(this).orEmpty())
        binding.inputDeviceName.setText(Settings.deviceName(this))
        binding.inputInterval.setText(Settings.intervalSecs(this).toString())
        binding.inputMaxPerPoll.setText(Settings.maxPerPoll(this).toString())
        binding.switchAutoStart.isChecked = Settings.autoStart(this)
        binding.switchNotifySent.isChecked = Settings.notifySent(this)
    }

    private fun showSetup(visible: Boolean) {
        binding.cardSetup.visibility = if (visible) View.VISIBLE else View.GONE
    }

    private fun persist(register: Boolean) {
        val url = binding.inputServerUrl.text?.toString()?.trim().orEmpty()
        val typedKey = binding.inputApiKey.text?.toString()?.trim().orEmpty()
        val apiKey = typedKey.ifBlank { Settings.apiKey(this).orEmpty() }
        val name = binding.inputDeviceName.text?.toString()?.trim().orEmpty()
            .ifBlank { Settings.defaultDeviceName() }
        val interval = binding.inputInterval.text?.toString()?.toIntOrNull() ?: Settings.DEFAULT_INTERVAL
        val maxPerPoll = binding.inputMaxPerPoll.text?.toString()?.toIntOrNull() ?: Settings.DEFAULT_MAX_PER_POLL

        if (!url.startsWith("http://") && !url.startsWith("https://")) {
            toast(getString(R.string.msg_invalid_url))
            return
        }
        if (apiKey.isBlank()) {
            toast(getString(R.string.msg_invalid_key))
            return
        }
        if (interval < Settings.MIN_INTERVAL) {
            toast(getString(R.string.msg_invalid_interval))
            return
        }

        if (url.startsWith("http://") && !isLocalHost(url)) {
            MaterialAlertDialogBuilder(this)
                .setTitle(R.string.setup_title)
                .setMessage(R.string.warn_insecure_url)
                .setPositiveButton(R.string.action_confirm) { _, _ -> applySettings(url, apiKey, name, interval, maxPerPoll, register) }
                .setNegativeButton(R.string.action_cancel, null)
                .show()
            return
        }

        applySettings(url, apiKey, name, interval, maxPerPoll, register)
    }

    private fun applySettings(
        url: String,
        apiKey: String,
        name: String,
        interval: Int,
        maxPerPoll: Int,
        register: Boolean
    ) {
        Settings.setServerUrl(this, url)
        Settings.setApiKey(this, apiKey)
        Settings.setDeviceName(this, name)
        Settings.setIntervalSecs(this, interval)
        Settings.setMaxPerPoll(this, maxPerPoll)
        Settings.setAutoStart(this, binding.switchAutoStart.isChecked)
        Settings.setNotifySent(this, binding.switchNotifySent.isChecked)

        val languageChanged = commitLanguage()

        showSetup(false)
        toast(getString(R.string.msg_settings_saved))

        if (register) {
            SmsGatewayService.start(this)
            toast(getString(R.string.msg_registered))
        }

        if (languageChanged) {
            // Só depois de gravar tudo: recriar a actividade antes perderia
            // o que o utilizador acabou de escrever.
            recreate()
            return
        }

        render(GatewayState.status.value)
    }

    private fun isLocalHost(url: String): Boolean {
        val host = url.removePrefix("http://").removePrefix("https://")
            .substringBefore('/').substringBefore(':').lowercase(Locale.ROOT)
        return host == "localhost" || host == "127.0.0.1" || host == "::1" ||
            host.startsWith("192.168.") || host.startsWith("10.") || host.startsWith("172.")
    }

    // ----------------------------------------------------------------- status

    private fun render(status: GatewayState.Status) {
        binding.valServer.text = Settings.serverUrl(this).ifBlank { getString(R.string.value_none) }
        binding.valDevice.text = Settings.deviceName(this)
        binding.valSim.text = Sims.labelFor(simOptions, Settings.subscriptionId(this))
        binding.valToken.text = maskToken(Settings.deviceToken(this))
        binding.valLastPoll.text = if (status.lastPollAt > 0) clock.format(Date(status.lastPollAt))
        else getString(R.string.value_none)
        binding.valPending.text = status.pending.toString()

        binding.valNextPoll.text = when {
            !status.running -> getString(R.string.value_none)
            status.nextPollAt <= 0L -> getString(R.string.value_none)
            else -> {
                val seconds = ((status.nextPollAt - System.currentTimeMillis()) / 1000L).coerceAtLeast(0)
                if (seconds < 60) "${seconds}s" else "${seconds / 60}m ${seconds % 60}s"
            }
        }

        val (label, colour) = when (status.phase) {
            GatewayState.Phase.DISABLED -> R.string.state_disabled to R.color.state_idle
            GatewayState.Phase.STARTING -> R.string.state_starting to R.color.state_idle
            GatewayState.Phase.IDLE -> R.string.state_idle to R.color.state_idle
            GatewayState.Phase.POLLING -> R.string.state_polling to R.color.lvl_info
            GatewayState.Phase.SENDING -> R.string.state_sending to R.color.lvl_info
            GatewayState.Phase.OK -> R.string.state_ok to R.color.state_ok
            GatewayState.Phase.ERROR -> R.string.state_error to R.color.state_error
        }
        binding.txtBadge.setText(label)
        binding.txtBadge.backgroundTintList =
            ColorStateList.valueOf(ContextCompat.getColor(this, colour))

        binding.valError.apply {
            val error = status.lastError
            text = error.orEmpty()
            visibility = if (error.isNullOrBlank()) View.GONE else View.VISIBLE
        }

        binding.btnToggleService.setText(
            if (status.running) R.string.action_stop else R.string.action_start
        )
        binding.btnRevoke.visibility =
            if (Settings.deviceToken(this).isNullOrBlank()) View.GONE else View.VISIBLE

        binding.progress.visibility = if (
            status.phase == GatewayState.Phase.POLLING ||
            status.phase == GatewayState.Phase.SENDING
        ) View.VISIBLE else View.GONE
    }

    private fun maskToken(token: String?): String = when {
        token.isNullOrBlank() -> getString(R.string.value_none)
        token.length <= 12 -> token
        else -> "${token.take(8)}…${token.takeLast(4)}  (${token.length} car.)"
    }

    private fun copyToken() {
        val token = Settings.deviceToken(this) ?: return
        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        clipboard.setPrimaryClip(ClipData.newPlainText("device_token", token))
        toast(getString(R.string.action_copied))
    }

    // ----------------------------------------------------------------- actions

    private fun toggleService() {
        if (GatewayState.status.value.running) {
            SmsGatewayService.stop(this)
        } else {
            if (!Settings.isConfigured(this)) {
                showSetup(true)
                toast(getString(R.string.msg_no_server_configured))
                return
            }
            SmsGatewayService.start(this)
        }
    }

    private fun ping() {
        if (!Settings.isConfigured(this)) {
            showSetup(true)
            toast(getString(R.string.msg_no_server_configured))
            return
        }
        binding.btnPing.isEnabled = false
        lifecycleScope.launch {
            val outcome = withContext(Dispatchers.IO) {
                runCatching {
                    ApiClient(
                        Settings.serverUrl(this@MainActivity),
                        Settings.apiKey(this@MainActivity).orEmpty(),
                        Settings.deviceToken(this@MainActivity)
                    ).heartbeat(Sims.labelFor(simOptions, Settings.subscriptionId(this@MainActivity)))
                }
            }
            binding.btnPing.isEnabled = true
            outcome.onSuccess { response ->
                val pending = response.optInt("pending", 0)
                LocalDb(this@MainActivity)
                    .add(Level.INFO, getString(R.string.log_ping_ok, pending))
                toast(getString(R.string.msg_ping_ok))
            }.onFailure { error ->
                LocalDb(this@MainActivity).add(Level.ERROR, getString(R.string.log_ping_fail, error.message))
                toast(error.message ?: getString(R.string.msg_ping_ok))
            }
        }
    }

    private fun confirmRevoke() {
        MaterialAlertDialogBuilder(this)
            .setTitle(R.string.confirm_revoke_title)
            .setMessage(R.string.confirm_revoke_body)
            .setPositiveButton(R.string.action_confirm) { _, _ ->
                Settings.clearDeviceToken(this)
                db.add(Level.WARN, getString(R.string.log_token_erased))
                render(GatewayState.status.value)
                toast(getString(R.string.msg_token_erased))
            }
            .setNegativeButton(R.string.action_cancel, null)
            .show()
    }

    private fun confirmClearLog() {
        MaterialAlertDialogBuilder(this)
            .setTitle(R.string.confirm_clear_title)
            .setMessage(R.string.confirm_clear_body)
            .setPositiveButton(R.string.action_confirm) { _, _ -> db.clear() }
            .setNegativeButton(R.string.action_cancel, null)
            .show()
    }

    // ------------------------------------------------------------- permissions

    private fun requiredPermissions(): Array<String> = buildList {
        add(Manifest.permission.SEND_SMS)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            add(Manifest.permission.POST_NOTIFICATIONS)
        }
    }.filter {
        ContextCompat.checkSelfPermission(this, it) != android.content.pm.PackageManager.PERMISSION_GRANTED
    }.toTypedArray()

    /** Optional: only needed to list the SIMs by name. */
    private fun missingPhonePermission(): Boolean = !Sims.hasPermission(this)

    private fun requestMissingPermissions() {
        val missing = requiredPermissions()
        if (missing.isNotEmpty()) permissionLauncher.launch(missing)
    }

    private fun batteryOptimised(): Boolean {
        val power = getSystemService(PowerManager::class.java) ?: return true
        return power.isIgnoringBatteryOptimizations(packageName)
    }

/**
 * O envio de SMS esta mesmo bloqueado pelo sistema?
 *
 * Só conta como problema se a app for elegível para o role de SMS. O Android
 * exige os componentes de uma app de mensagens (SMS_DELIVER, WAP_PUSH_DELIVER
 * e SEND_RESPOND_VIA_MESSAGE) e o gateway não os declara: envia SMS, mas não
 * tem caixa de entrada nem responde a SMS recebidos. Sem esses componentes,
 * createRequestRoleIntent rebenta — o aviso nunca se resolvia e o botão não
 * fazia nada.
 *
 * Enviar SMS com a permissão SEND_SMS não exige ser a app predefinida, por
 * isso esta verificação só deve incomodar quando o pedido é mesmo possível.
 */
private fun missingDefaultSmsRole(): Boolean {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return false
    val roles = getSystemService(RoleManager::class.java) ?: return false
    if (!roles.isRoleAvailable(RoleManager.ROLE_SMS)) return false
    if (roles.isRoleHeld(RoleManager.ROLE_SMS)) return false

    // Elegível = o Android consegue construir o pedido de role.
    return runCatching { roles.createRequestRoleIntent(RoleManager.ROLE_SMS) }.isSuccess
}

    private fun currentPermAction(): PermAction = when {
        requiredPermissions().isNotEmpty() -> PermAction.GRANT
        missingPhonePermission() && simOptions.size > 1 -> PermAction.PHONE
        !batteryOptimised() -> PermAction.BATTERY
        missingDefaultSmsRole() -> PermAction.SMS_ROLE
        else -> PermAction.NONE
    }

    private fun updatePermissionCard() {
        when (currentPermAction()) {
            PermAction.GRANT -> {
                binding.cardPerms.visibility = View.VISIBLE
                binding.permTitle.setText(R.string.perm_required_title)
                binding.permBody.setText(R.string.perm_required_body)
                binding.btnPermAction.setText(R.string.perm_grant)
            }
            PermAction.PHONE -> {
                binding.cardPerms.visibility = View.VISIBLE
                binding.permTitle.setText(R.string.perm_phone_title)
                binding.permBody.setText(R.string.perm_phone_body)
                binding.btnPermAction.setText(R.string.perm_grant)
            }
            PermAction.BATTERY -> {
                binding.cardPerms.visibility = View.VISIBLE
                binding.permTitle.setText(R.string.perm_battery_title)
                binding.permBody.setText(R.string.perm_battery_body)
                binding.btnPermAction.setText(R.string.perm_battery_open)
            }
            PermAction.SMS_ROLE -> {
                binding.cardPerms.visibility = View.VISIBLE
                binding.permTitle.setText(R.string.perm_sms_blocked_title)
                binding.permBody.setText(R.string.perm_sms_blocked_body)
                binding.btnPermAction.setText(R.string.perm_sms_blocked_open)
            }
            PermAction.NONE -> binding.cardPerms.visibility = View.GONE
        }
    }

    private fun onPermAction() {
        when (currentPermAction()) {
            PermAction.GRANT -> requestMissingPermissions()
            PermAction.PHONE -> permissionLauncher.launch(arrayOf(Manifest.permission.READ_PHONE_STATE))
            PermAction.BATTERY -> {
                val request = android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS
                val list = android.provider.Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS
                val intent = Intent(request, Uri.parse("package:$packageName"))
                runCatching { startActivity(intent) }
                    .onFailure {
                        runCatching { startActivity(Intent(list)) }
                    }
            }
            PermAction.SMS_ROLE -> requestSmsRole()
            PermAction.NONE -> Unit
        }
    }

    /**
     * Pede a app de SMS predefinida, com recurso a_SETTINGS no fim.
     *
     * Qualquer um dos passos pode não existir no equipamento. Antes, uma falha
     * deixava o botão sem efeito nenhum; agora sempre abre alguma coisa.
     */
    private fun requestSmsRole() {
        val roles = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            getSystemService(RoleManager::class.java)
        } else {
            null
        }

        if (roles != null) {
            val request = runCatching { roles.createRequestRoleIntent(RoleManager.ROLE_SMS) }.getOrNull()
            if (request != null) {
                runCatching { roleLauncher.launch(request) }.onSuccess { return }
            }
        }

        runCatching { startActivity(Intent(TelephonyIntents.ACTION_CHANGE_DEFAULT)) }
            .onSuccess { return }

        openAppSettings()
    }

    /** Abre as definições desta app. É o último recurso, mas nunca falha. */
    private fun openAppSettings() {
        val intent = Intent(
            android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
            Uri.parse("package:$packageName")
        )
        runCatching { startActivity(intent) }
    }

    // ------------------------------------------------------------------ log

    private fun toggleLog() {
        applyLogCollapsed(binding.listLog.visibility == View.VISIBLE, save = true)
    }

    /**
     * Recolhido, o painel reduz-se a faixa do cabecalho (peso 0, altura
     * natural) e a area de configuracao absorve o espaco todo.
     *
     * A altura tem de ser WRAP_CONTENT e nao 0: com 0 o painel desaparecia
     * por completo, incluindo o cabecalho, e deixava de haver forma de voltar
     * a abrir. O chevron e a linha do titulo ficam sempre visiveis.
     */
    private fun applyLogCollapsed(collapsed: Boolean, save: Boolean) {
        val params = binding.logPanel.layoutParams as LinearLayout.LayoutParams

        if (collapsed) {
            params.height = LinearLayout.LayoutParams.WRAP_CONTENT
            params.weight = 0f
            binding.listLog.visibility = View.GONE
            binding.txtLogEmpty.visibility = View.GONE
        } else {
            params.height = 0
            params.weight = LOG_PANEL_WEIGHT
            binding.listLog.visibility = View.VISIBLE
            binding.txtLogEmpty.visibility = if (adapter.currentList.isEmpty()) View.VISIBLE else View.GONE
        }

        binding.logPanel.layoutParams = params
        binding.logChevron.setImageResource(
            if (collapsed) R.drawable.ic_chevron_right else R.drawable.ic_chevron_down
        )
        binding.logHeader.contentDescription = getString(
            if (collapsed) R.string.action_expand_log else R.string.action_collapse_log
        )
        if (save) Settings.setLogCollapsed(this, collapsed)
    }

    // ----------------------------------------------------------- languages

    /**
     * O idioma só é aplicado quando o utilizador guarda. Aplicá-lo de
     * imediato recriava a actividade e perdia o que estivesse a escrever nos
     * outros campos (endereço do servidor, chave da API).
     */
    private fun setupLanguagePicker() {
        val labels = listOf(
            getString(R.string.lang_system),
            getString(R.string.lang_pt),
            getString(R.string.lang_es),
            getString(R.string.lang_en),
            getString(R.string.lang_fr)
        )

        binding.inputLanguage.setAdapter(FixedListAdapter(this, labels))
        binding.inputLanguage.threshold = 0

        val stored = Settings.language(this)
        val index = UiLanguage.SUPPORTED.indexOf(stored).takeIf { it >= 0 } ?: 0
        pendingLanguage = UiLanguage.SUPPORTED[index]
        binding.inputLanguage.setText(labels[index], false)
        binding.inputLanguage.setOnItemClickListener { _, _, position, _ ->
            pendingLanguage = UiLanguage.SUPPORTED[position]
        }
    }

    /** Aplica o idioma escolhido, se mudou, e recria a actividade. */
    private fun commitLanguage(): Boolean {
        val previous = Settings.language(this)
        if (pendingLanguage == previous) return false

        Settings.setLanguage(this, pendingLanguage)
        UiLanguage.apply(pendingLanguage)
        return true
    }

    // ------------------------------------------------------------------ SIMs

    private fun refreshSims() {
        simOptions = Sims.active(this)
        val active = Settings.subscriptionId(this)

        if (simOptions.isEmpty()) {
            binding.layoutSim.helperText = if (Sims.hasPermission(this)) {
                getString(R.string.sim_none)
            } else {
                getString(R.string.sim_permission_needed)
            }
            simLabels = listOf(getString(R.string.sim_locked))
            simIds = listOf(Sims.AUTO_SUBSCRIPTION_ID)
            binding.inputSim.setAdapter(FixedListAdapter(this, simLabels))
            binding.inputSim.setText(simLabels.first(), false)
            binding.inputSim.isEnabled = false
            return
        }

        binding.inputSim.isEnabled = true
        simLabels = buildList {
            add(getString(R.string.sim_auto))
            simOptions.forEach { add(it.displayLabel) }
        }
        simIds = buildList {
            add(Sims.AUTO_SUBSCRIPTION_ID)
            simOptions.forEach { add(it.subscriptionId) }
        }

        val index = simIds.indexOf(active).takeIf { it >= 0 } ?: 0
        // Adaptador sem filtragem: com o filtro do MaterialAutoCompleteTextView,
        // escolher SIM 2 e voltar a abrir mostrava apenas "SIM 2".
        binding.inputSim.setAdapter(FixedListAdapter(this, simLabels))
        binding.inputSim.threshold = 0
        binding.inputSim.setText(simLabels[index], false)
        binding.inputSim.setOnItemClickListener { _, _, position, _ ->
            Settings.setSubscriptionId(this, simIds[position])
            if (position == 0) {
                binding.layoutSim.helperText = getString(R.string.sim_auto)
            } else {
                binding.layoutSim.helperText = simOptions[position - 1].shortLabel
            }
            render(GatewayState.status.value)
        }
        binding.layoutSim.helperText = if (index == 0) {
            getString(R.string.sim_auto)
        } else {
            simOptions[index - 1].shortLabel
        }
    }

    private object TelephonyIntents {
        const val ACTION_CHANGE_DEFAULT = "android.provider.Telephony.ACTION_CHANGE_DEFAULT"
    }

    /** Peso do painel do registo quando expandido. Tem de bater com o layout. */
    private companion object {
        const val LOG_PANEL_WEIGHT = 1.4f
    }

    // -------------------------------------------------------------------- misc

    private fun refreshLog() {
        val entries = db.recent(200)
        adapter.submitList(entries)

        // Nunca revelar o painel: o utilizador recolheu-o de proposito.
        if (binding.listLog.visibility == View.VISIBLE) {
            binding.txtLogEmpty.visibility = if (entries.isEmpty()) View.VISIBLE else View.GONE
        }

        binding.logTitle.text = if (entries.isEmpty()) {
            getString(R.string.log_title)
        } else {
            getString(R.string.log_title_count, entries.size)
        }
    }

    private fun toast(message: String) =
        Toast.makeText(this, message, Toast.LENGTH_SHORT).show()
}
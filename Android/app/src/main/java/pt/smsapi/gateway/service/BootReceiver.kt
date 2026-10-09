package pt.smsapi.gateway.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import pt.smsapi.gateway.data.Settings

class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action ?: return
        if (action !in HANDLED_ACTIONS) return

        val app = context.applicationContext
        if (!Settings.autoStart(app)) return
        if (!Settings.isConfigured(app)) return

        runCatching { SmsGatewayService.start(app) }
    }

    private companion object {
        val HANDLED_ACTIONS = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON"
        )
    }
}
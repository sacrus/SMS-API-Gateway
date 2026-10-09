package pt.smsapi.gateway

import android.app.Application
import pt.smsapi.gateway.data.UiLanguage
import pt.smsapi.gateway.service.Notifications

class GatewayApp : Application() {
    override fun onCreate() {
        super.onCreate()

        // Must run before any activity exists, otherwise AppCompat cannot switch
        // the locale on older Android versions.
        UiLanguage.applyStored(this)

        Notifications.ensureChannels(this)
    }
}
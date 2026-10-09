package pt.smsapi.gateway.sms

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class SmsResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val requestId = intent.getStringExtra(SmsSender.EXTRA_REQUEST_ID) ?: return
        SmsSender.dispatch(requestId, resultCode)
    }
}
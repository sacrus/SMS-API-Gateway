package pt.smsapi.gateway.service

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.core.graphics.drawable.IconCompat
import pt.smsapi.gateway.R
import pt.smsapi.gateway.ui.MainActivity

object Notifications {

    const val CHANNEL_STATUS = "gateway_status"
    const val CHANNEL_ALERT = "gateway_alert"

    const val NOTIF_STATUS_ID = 1001
    const val NOTIF_ALERT_ID = 1002

    fun ensureChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return

        val status = NotificationChannel(
            CHANNEL_STATUS,
            context.getString(R.string.notif_channel_status),
            NotificationManager.IMPORTANCE_MIN
        ).apply {
            description = context.getString(R.string.notif_channel_status_desc)
            setShowBadge(false)
            enableVibration(false)
            setSound(null, null)
        }

        val alert = NotificationChannel(
            CHANNEL_ALERT,
            context.getString(R.string.notif_channel_alert),
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = context.getString(R.string.notif_channel_alert_desc)
        }

        manager.createNotificationChannel(status)
        manager.createNotificationChannel(alert)
    }

    fun status(context: Context, text: String): Notification {
        val builder = NotificationCompat.Builder(context, CHANNEL_STATUS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(context.getString(R.string.notif_title))
            .setContentText(text)
            .setContentIntent(contentIntent(context))
            .setOngoing(true)
            .setSilent(true)
            .setShowWhen(false)
            .setOnlyAlertOnce(true)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .addAction(
                action(context, R.string.action_sync, SmsGatewayService.ACTION_POLL_NOW, REQUEST_POLL)
            )
            .addAction(
                action(context, R.string.action_stop, SmsGatewayService.ACTION_STOP, REQUEST_STOP)
            )
        return builder.build()
    }

    private fun action(context: Context, titleRes: Int, serviceAction: String, requestCode: Int) =
        NotificationCompat.Action.Builder(
            IconCompat.createWithResource(context, R.drawable.ic_notification),
            context.getString(titleRes),
            serviceIntent(context, serviceAction, requestCode)
        ).build()

    fun alert(context: Context, title: String, text: String) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED
        ) return

        val notification = NotificationCompat.Builder(context, CHANNEL_ALERT)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setContentIntent(contentIntent(context))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()

        try {
            NotificationManagerCompat.from(context).notify(NOTIF_ALERT_ID, notification)
        } catch (ignored: SecurityException) {
        }
    }

    private fun contentIntent(context: Context): PendingIntent = PendingIntent.getActivity(
        context,
        REQUEST_CONTENT,
        Intent(context, MainActivity::class.java)
            .setFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
    )

    private fun serviceIntent(context: Context, action: String, requestCode: Int): PendingIntent =
        PendingIntent.getService(
            context,
            requestCode,
            Intent(context, SmsGatewayService::class.java).setAction(action),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

    private const val REQUEST_CONTENT = 10
    private const val REQUEST_POLL = 11
    private const val REQUEST_STOP = 12
}
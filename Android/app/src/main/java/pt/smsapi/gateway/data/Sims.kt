package pt.smsapi.gateway.data

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.telephony.SubscriptionInfo
import android.telephony.SubscriptionManager
import androidx.core.content.ContextCompat

/**
 * One active SIM (a subscription, in Android terms).
 *
 * [subscriptionId] is what SmsManager.createForSubscriptionId() expects;
 * [slotIndex] is only used for display.
 */
data class SimOption(
    val subscriptionId: Int,
    val slotIndex: Int,
    val carrierName: String,
    val displayLabel: String
) {
    /** Short label for logs and notifications. */
    val shortLabel: String get() = carrierName.ifBlank { "SIM $slotIndex" }
}

/**
 * Reads the active SIMs. Details (carrier name, slot) require READ_PHONE_STATE;
 * without it we can still count active subscriptions and fall back to generic
 * labels, because sending through a specific subscription needs no permission.
 */
object Sims {

    const val AUTO_SUBSCRIPTION_ID = -1

    fun hasPermission(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) ==
            PackageManager.PERMISSION_GRANTED
    }

    @SuppressLint("MissingPermission")
    fun activeCount(context: Context): Int = try {
        context.getSystemService(SubscriptionManager::class.java)?.activeSubscriptionInfoList?.size ?: 0
    } catch (e: Exception) {
        try {
            context.getSystemService(SubscriptionManager::class.java)?.activeSubscriptionInfoCount ?: 0
        } catch (ignored: Exception) {
            0
        }
    }

    @SuppressLint("MissingPermission")
    fun active(context: Context): List<SimOption> {
        val manager = context.getSystemService(SubscriptionManager::class.java) ?: return emptyList()

        val infos: List<SubscriptionInfo> = try {
            manager.activeSubscriptionInfoList?.toList().orEmpty()
        } catch (e: SecurityException) {
            // Permission refused: no ids available, so no explicit selection.
            return emptyList()
        } catch (e: Exception) {
            return emptyList()
        }

        return infos
            .sortedBy { it.simSlotIndex }
            .map { info ->
                val slot = info.simSlotIndex + 1
                val carrier = info.carrierName?.toString()?.trim().orEmpty()
                SimOption(
                    subscriptionId = info.subscriptionId,
                    slotIndex = slot,
                    carrierName = carrier,
                    displayLabel = if (carrier.isEmpty()) "SIM $slot" else "SIM $slot · $carrier"
                )
            }
    }

    /** The option matching [subscriptionId], or null when it is no longer active. */
    fun find(options: List<SimOption>, subscriptionId: Int): SimOption? =
        options.firstOrNull { it.subscriptionId == subscriptionId }

    fun labelFor(options: List<SimOption>, subscriptionId: Int): String = when {
        subscriptionId == AUTO_SUBSCRIPTION_ID && options.isNotEmpty() ->
            "Automática (${options.first().shortLabel})"

        subscriptionId == AUTO_SUBSCRIPTION_ID -> "Automática"
        else -> find(options, subscriptionId)?.shortLabel ?: "SIM indisponível"
    }
}
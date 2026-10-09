package pt.smsapi.gateway.sms

import android.app.Activity
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.telephony.SmsManager
import android.util.Log
import kotlinx.coroutines.suspendCancellableCoroutine
import pt.smsapi.gateway.data.Sims
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicReference
import kotlin.coroutines.resume

data class SmsResult(val ok: Boolean, val error: String?, val parts: Int, val subscriptionId: Int)

class SmsSender(private val context: Context) {

    /**
     * Sends [body] through the given subscription, or through the system default
     * when [subscriptionId] is [Sims.AUTO_SUBSCRIPTION_ID]. Falls back to the
     * default subscription if the chosen SIM has gone away.
     */
    suspend fun send(phone: String, body: String, subscriptionId: Int = Sims.AUTO_SUBSCRIPTION_ID): SmsResult =
        suspendCancellableCoroutine { continuation ->
            if (body.isBlank()) {
                continuation.resume(SmsResult(false, "mensagem vazia", 0, subscriptionId))
                return@suspendCancellableCoroutine
            }

            val manager = resolveManager(subscriptionId)
            if (manager == null) {
                continuation.resume(SmsResult(false, "serviço de SMS indisponível", 0, subscriptionId))
                return@suspendCancellableCoroutine
            }

            val requestId = UUID.randomUUID().toString()
            val parts = ArrayList<String>()
            parts.addAll(manager.divideMessage(body))
            if (parts.isEmpty()) parts.add(body)
            val aggregation = Aggregation(parts.size, subscriptionId)
            registry[requestId] = aggregation

            aggregation.onDone = { result ->
                registry.remove(requestId)
                if (continuation.isActive) continuation.resume(result)
            }
            continuation.invokeOnCancellation { registry.remove(requestId) }

            try {
                val intents = ArrayList<PendingIntent>(parts.size)
                for (index in parts.indices) {
                    val intent = Intent(context, SmsResultReceiver::class.java).apply {
                        action = ACTION_SENT
                        putExtra(EXTRA_REQUEST_ID, requestId)
                        putExtra(EXTRA_PARTS, parts.size)
                    }
                    intents += PendingIntent.getBroadcast(
                        context,
                        (requestId.hashCode() * 31 + index) and 0x7FFF_FFFF,
                        intent,
                        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
                    )
                }
                if (parts.size > 1) {
                    manager.sendMultipartTextMessage(phone, null, parts, intents, null)
                } else {
                    manager.sendTextMessage(phone, null, body, intents[0], null)
                }
            } catch (e: Exception) {
                registry.remove(requestId)
                if (continuation.isActive) {
                    val reason = describeException(e)
                    Log.w(TAG, "Falha ao enviar para $phone", e)
                    continuation.resume(SmsResult(false, reason, parts.size, subscriptionId))
                }
            }
        }

    /**
     * Resolves the SmsManager bound to the chosen SIM. If the subscription is no
     * longer active (card removed, eSIM deactivated) it quietly falls back to
     * the default rather than failing the message.
     */
    private fun resolveManager(subscriptionId: Int): SmsManager? {
        val fallback = try {
            context.getSystemService(SmsManager::class.java)
        } catch (e: Exception) {
            null
        }

        if (subscriptionId == Sims.AUTO_SUBSCRIPTION_ID) return fallback

        // Android 12 made the instance method public; before that only the
        // deprecated static one was available. Either way a dead SIM must not
        // break the message, so fall back to the default manager.
        return try {
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.S) {
                fallback?.createForSubscriptionId(subscriptionId) ?: fallback
            } else {
                @Suppress("DEPRECATION")
                SmsManager.getSmsManagerForSubscriptionId(subscriptionId) ?: fallback
            }
        } catch (e: Exception) {
            Log.w(TAG, "subscrição $subscriptionId indisponível, a usar a predefinida", e)
            fallback
        }
    }

    private fun describeException(e: Exception): String = when (e) {
        is SecurityException -> "sem permissão para enviar SMS"
        is IllegalArgumentException -> "número ou subscrição inválidos"
        else -> e.message ?: "falha ao enviar (${e.javaClass.simpleName})"
    }

    private class Aggregation(private val total: Int, private val subscriptionId: Int) {
        private val remaining = AtomicInteger(total)
        private val failures = AtomicInteger(0)
        private val firstError = AtomicReference<String?>(null)

        @Volatile
        var onDone: ((SmsResult) -> Unit)? = null

        fun report(resultCode: Int) {
            if (resultCode != Activity.RESULT_OK) {
                failures.incrementAndGet()
                firstError.compareAndSet(null, describe(resultCode))
            }
            if (remaining.decrementAndGet() == 0) {
                onDone?.invoke(SmsResult(failures.get() == 0, firstError.get(), total, subscriptionId))
            }
        }
    }

    companion object {
        const val ACTION_SENT = "pt.smsapi.gateway.SMS_SENT"
        const val EXTRA_REQUEST_ID = "request_id"
        const val EXTRA_PARTS = "parts"

        private val registry = ConcurrentHashMap<String, Aggregation>()

        private const val TAG = "SmsSender"

        internal fun dispatch(requestId: String, resultCode: Int) {
            registry[requestId]?.report(resultCode)
        }

        fun describe(resultCode: Int): String = when (resultCode) {
            Activity.RESULT_OK -> "enviado"
            1 -> "erro genérico do modem"
            2 -> "sem serviço de rede"
            3 -> "PDU inválido"
            4 -> "rádio desligado"
            5 -> "número ou conteúdo inválido"
            6 -> "memória insuficiente"
            8 -> "limite de SMS excedido"
            12 -> "operação não permitida pelo sistema"
            else -> "código do modem $resultCode"
        }
    }
}
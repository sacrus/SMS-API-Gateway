package pt.smsapi.gateway.net

import android.os.Build
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import pt.smsapi.gateway.crypto.Sign
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

class ApiException(
    val status: Int,
    val code: String,
    message: String
) : IOException(message)

data class QueuedSms(
    val id: Long,
    val phone: String,
    val body: String,
    val attempts: Int,
    val createdAt: Long,
    val encoding: String,
    val serverSegments: Int
)

/**
 * Talks to the self-hosted PHP API. Every request carries a shared-secret API
 * key plus an HMAC-SHA256 signature over method, endpoint, timestamp, nonce and
 * body digest, so the traffic cannot be replayed or forged.
 */
class ApiClient(
    private val baseUrl: String,
    private val apiKey: String,
    private val deviceToken: String?
) {

    companion object {
        private const val CONNECT_TIMEOUT = 20_000
        private const val READ_TIMEOUT = 25_000

        const val APP_VERSION = "1.0.0"
        private const val TAG = "SmsApiClient"

        fun userAgent(): String {
            val model = Build.MODEL?.replace(" ", "_") ?: "device"
            val release = Build.VERSION.RELEASE ?: "?"
            return "SMSAPI-Android/$APP_VERSION (Android $release; $model)"
        }
    }

    // --------------------------------------------------------------- endpoints

    fun register(
        deviceName: String,
        installId: String,
        manufacturer: String,
        model: String,
        osVersion: String,
        simLabel: String
    ): JSONObject {
        val body = JSONObject()
            .put("device_name", deviceName)
            .put("install_id", installId)
            .put("manufacturer", manufacturer)
            .put("model", model)
            .put("os_version", osVersion)
            .put("app_version", APP_VERSION)
            .put("sim_label", simLabel)
        return request("register", "POST", body)
    }

    fun heartbeat(simLabel: String): JSONObject =
        request("heartbeat", "POST", JSONObject().put("sim_label", simLabel))

    fun poll(limit: Int): List<QueuedSms> {
        val response = request("poll", "POST", JSONObject().put("limit", limit))
        val array: JSONArray = response.optJSONArray("messages") ?: JSONArray()
        val out = ArrayList<QueuedSms>(array.length())
        for (i in 0 until array.length()) {
            val item = array.optJSONObject(i) ?: continue
            val id = item.optLong("id", 0L)
            val phone = item.optString("phone", "")
            if (id <= 0L || phone.isBlank()) continue
            out += QueuedSms(
                id = id,
                phone = phone.trim(),
                body = item.optString("body", ""),
                attempts = item.optInt("attempts", 1),
                createdAt = item.optLong("created_at", 0L),
                encoding = item.optString("encoding", "gsm7"),
                serverSegments = item.optInt("segments", 1)
            )
        }
        return out
    }

    fun ack(ids: List<Long>): JSONObject =
        request("ack", "POST", JSONObject().put("ids", JSONArray(ids)))

    fun fail(id: Long, reason: String): JSONObject =
        request("fail", "POST", JSONObject().put("id", id).put("reason", reason.take(300)))

    // ------------------------------------------------------------------ plumbing

    private fun endpointUrl(path: String): String = baseUrl.trimEnd('/') + "/" + path

    private fun request(
        endpoint: String,
        method: String,
        payload: JSONObject?,
        attempt: Int = 0
    ): JSONObject {
        val body = payload?.toString()?.toByteArray(Charsets.UTF_8) ?: ByteArray(0)
        val timestamp = (System.currentTimeMillis() / 1000L).toString()
        val nonce = UUID.randomUUID().toString().replace("-", "")

        val canonical = buildString {
            append(method.uppercase()).append('\n')
            append(endpoint).append('\n')
            append(timestamp).append('\n')
            append(nonce).append('\n')
            append(Sign.sha256Hex(body))
        }

        val connection = (URL(endpointUrl(endpoint)).openConnection() as HttpURLConnection).apply {
            requestMethod = method.uppercase()
            connectTimeout = CONNECT_TIMEOUT
            readTimeout = READ_TIMEOUT
            useCaches = false
            setRequestProperty("Accept", "application/json")
            setRequestProperty("User-Agent", userAgent())
            setRequestProperty("X-Api-Key", apiKey)
            setRequestProperty("X-Timestamp", timestamp)
            setRequestProperty("X-Nonce", nonce)
            setRequestProperty("X-Signature", Sign.hmacSha256Hex(apiKey, canonical))
            if (!deviceToken.isNullOrBlank()) {
                setRequestProperty("X-Device-Token", deviceToken)
            }
            // Android's HttpURLConnection is backed by a shared connection pool.
            // When the pool hands back a socket the server already closed, the
            // response is truncated ("unexpected end of stream"). Forcing a fresh
            // connection on the retry skips that dead socket.
            if (attempt > 0) {
                setRequestProperty("Connection", "close")
            }
            if (body.isNotEmpty()) {
                doOutput = true
                setFixedLengthStreamingMode(body.size)
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
            }
        }

        try {
            if (body.isNotEmpty()) {
                connection.outputStream.use { it.write(body) }
            }

            val status = connection.responseCode
            val text = (if (status in 200..299) connection.inputStream else connection.errorStream)
                ?.bufferedReader(Charsets.UTF_8)
                ?.use { it.readText() }
                .orEmpty()

            if (status !in 200..299) {
                var code = "http_$status"
                var message = text.ifBlank { "HTTP $status" }
                try {
                    val error = JSONObject(text)
                    code = error.optString("error", code)
                    message = error.optString("message", message)
                } catch (ignored: Exception) {
                }
                throw ApiException(status, code, message)
            }

            return if (text.isBlank()) JSONObject() else JSONObject(text)
        } catch (e: ApiException) {
            throw e
        } catch (e: Exception) {
            if (attempt == 0 && isTransient(e)) {
                Log.w(TAG, "retrying $endpoint on a fresh connection", e)
                return request(endpoint, method, payload, attempt + 1)
            }
            throw IOException(explain(e), e)
        } finally {
            connection.disconnect()
        }
    }

    /**
     * Failures that a fresh connection can plausibly fix: a pooled socket the
     * server had already closed, a proxy dropping an idle connection, or a
     * network handover mid-request. A wrong key or a 4xx is not transient.
     */
    private fun isTransient(e: Exception): Boolean {
        val text = chainText(e)

        return listOf(
            "unexpected end of stream",
            "connection reset",
            "econnreset",
            "socket closed",
            "closed by remote",
            "stream was reset",
            "premature",
            "broken pipe",
            "software caused connection abort",
            "timeout",
            "timed out"
        ).any { text.contains(it) }
    }

    /**
     * Turns framework and network messages into something a person can act on.
     * The raw text ("unexpected end of stream on com.android.okhttp.Address@…")
     * says nothing about what to do next.
     */
    private fun explain(e: Exception): String {
        val text = chainText(e)

        return when {
            text.contains("cleartext") ->
                "O Android bloqueou a ligação: o endereço usa http://. Use https:// ou, numa rede local, o IP privado do servidor."

            text.contains("unexpected end of stream") || text.contains("connection reset") ->
                "O servidor fechou a ligação a meio da resposta. Algum proxy ou balanceador corta ligações inativas; a app já tentou novamente numa ligação nova."

            text.contains("timeout") || text.contains("timed out") ->
                "O servidor não respondeu a tempo. Verifique a ligação e se o endereço está correto."

            text.contains("unable to resolve") || text.contains("unknownhost") ->
                "O endereço do servidor não foi resolvido. Verifique o domínio."

            text.contains("econnrefused") || text.contains("failed to connect") ->
                "Ligação recusada. Não há nada a responder nesse endereço e porta."

            text.contains("ehostunreach") || text.contains("enetunreach") ->
                "Sem rota para o servidor. Verifique a ligação de dados do telefone."

            text.contains("certpathvalidator") || text.contains("ssl") ->
                "Certificado inválido ou expirado no servidor."

            else -> e.message?.takeIf { it.isNotBlank() } ?: e.javaClass.simpleName
        }
    }

    private fun chainText(e: Throwable): String {
        val builder = StringBuilder()
        var current: Throwable? = e
        var depth = 0
        while (current != null && depth++ < 5) {
            builder.append(current.javaClass.name).append(' ')
                .append(current.message.orEmpty()).append(' ')
            current = current.cause
        }
        return builder.toString().lowercase()
    }
}
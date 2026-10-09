package pt.smsapi.gateway.crypto

import java.security.MessageDigest
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

object Sign {

    private const val HEX = "0123456789abcdef"

    fun sha256Hex(data: ByteArray): String =
        toHex(MessageDigest.getInstance("SHA-256").digest(data))

    fun sha256Hex(data: String): String = sha256Hex(data.toByteArray(Charsets.UTF_8))

    fun hmacSha256Hex(key: String, data: String): String {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(key.toByteArray(Charsets.UTF_8), "HmacSHA256"))
        return toHex(mac.doFinal(data.toByteArray(Charsets.UTF_8)))
    }

    private fun toHex(bytes: ByteArray): String {
        val out = StringBuilder(bytes.size * 2)
        for (b in bytes) {
            val v = b.toInt() and 0xFF
            out.append(HEX[v ushr 4]).append(HEX[v and 0x0F])
        }
        return out.toString()
    }
}
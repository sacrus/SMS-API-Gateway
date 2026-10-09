package pt.smsapi.gateway.crypto

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Stores secrets (API key and device token) encrypted with AES-256-GCM.
 * The key itself never leaves the Android Keystore, so the values on disk are
 * useless to anyone who extracts the app's data directory.
 */
object SecureStore {

    private const val PREFS = "smsapi_secure"
    private const val ALIAS = "smsapi.master.v1"
    private const val TRANSFORM = "AES/GCM/NoPadding"
    private const val GCM_TAG_BITS = 128
    private const val IV_BYTES = 12

    private fun masterKey(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (keyStore.getEntry(ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(
                ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .setRandomizedEncryptionRequired(true)
                .build()
        )
        return generator.generateKey()
    }

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun put(context: Context, name: String, value: String) {
        val cipher = Cipher.getInstance(TRANSFORM)
        cipher.init(Cipher.ENCRYPT_MODE, masterKey())
        val ciphertext = cipher.doFinal(value.toByteArray(Charsets.UTF_8))
        val packed = cipher.iv + ciphertext
        prefs(context).edit()
            .putString(name, Base64.encodeToString(packed, Base64.NO_WRAP))
            .apply()
    }

    fun get(context: Context, name: String): String? {
        val stored = prefs(context).getString(name, null) ?: return null
        return try {
            val packed = Base64.decode(stored, Base64.NO_WRAP)
            val iv = packed.copyOfRange(0, IV_BYTES)
            val data = packed.copyOfRange(IV_BYTES, packed.size)
            val cipher = Cipher.getInstance(TRANSFORM)
            cipher.init(Cipher.DECRYPT_MODE, masterKey(), GCMParameterSpec(GCM_TAG_BITS, iv))
            String(cipher.doFinal(data), Charsets.UTF_8)
        } catch (e: Exception) {
            prefs(context).edit().remove(name).apply()
            null
        }
    }

    fun remove(context: Context, name: String) {
        prefs(context).edit().remove(name).apply()
    }
}
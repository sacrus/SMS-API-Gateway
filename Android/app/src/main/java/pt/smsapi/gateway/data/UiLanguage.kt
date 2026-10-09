package pt.smsapi.gateway.data

import android.content.Context
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat

/**
 * UI language handling.
 *
 * The choice is stored locally and re-applied on every process start, which
 * works the same way from Android 7 up to Android 16 — no reliance on
 * AppCompat's own storage, so a system language change never silently wipes
 * the setting.
 */
object UiLanguage {

    const val SYSTEM = ""

    /** BCP-47 tags offered in the picker, in display order. */
    val SUPPORTED = listOf(SYSTEM, "pt", "es", "en", "fr")

    fun isSupported(tag: String): Boolean = SUPPORTED.contains(tag)

    /**
     * Applies the stored language. Called from Application.onCreate, before any
     * activity exists, which is the only place this is guaranteed to work on
     * every Android version.
     */
    fun applyStored(context: Context) {
        val stored = Settings.language(context)
        val current = AppCompatDelegate.getApplicationLocales().toLanguageTags()

        // LocaleListCompat reports an empty list for the system default, so an
        // empty stored value means "leave it alone".
        if (current == stored) return

        runCatching {
            AppCompatDelegate.setApplicationLocales(
                if (stored.isEmpty()) LocaleListCompat.getEmptyLocaleList()
                else LocaleListCompat.forLanguageTags(stored)
            )
        }
    }

    fun apply(tag: String) {
        runCatching {
            AppCompatDelegate.setApplicationLocales(
                if (tag.isEmpty()) LocaleListCompat.getEmptyLocaleList()
                else LocaleListCompat.forLanguageTags(tag)
            )
        }
    }
}
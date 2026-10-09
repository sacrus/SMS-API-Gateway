package pt.smsapi.gateway.data

data class LogEntry(
    val id: Long,
    val timestamp: Long,
    val level: String,
    val phone: String?,
    val body: String?,
    val remoteId: Long?,
    val detail: String?
)

object Level {
    const val INFO = "info"
    const val WARN = "warn"
    const val ERROR = "error"
    const val SEND = "send"
}
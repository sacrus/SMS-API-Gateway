package pt.smsapi.gateway.service

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

object GatewayState {

    enum class Phase { DISABLED, STARTING, IDLE, POLLING, SENDING, OK, ERROR }

    data class Status(
        val running: Boolean = false,
        val phase: Phase = Phase.DISABLED,
        val lastPollAt: Long = 0L,
        val nextPollAt: Long = 0L,
        val pending: Int = 0,
        val sentThisCycle: Int = 0,
        val totalSent: Int = 0,
        val lastError: String? = null,
        val registered: Boolean = false
    )

    private val _status = MutableStateFlow(Status())
    val status: StateFlow<Status> = _status.asStateFlow()

    fun update(transform: (Status) -> Status) {
        _status.value = transform(_status.value)
    }

    fun setPhase(phase: Phase) = update { it.copy(phase = phase) }

    fun setError(message: String) =
        update { it.copy(phase = Phase.ERROR, lastError = message) }
}
package pt.smsapi.gateway.ui

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import pt.smsapi.gateway.R
import pt.smsapi.gateway.data.Level
import pt.smsapi.gateway.data.LogEntry
import pt.smsapi.gateway.databinding.ItemLogBinding
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class LogAdapter : ListAdapter<LogEntry, LogAdapter.Holder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
        val binding = ItemLogBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return Holder(binding)
    }

    override fun onBindViewHolder(holder: Holder, position: Int) = holder.bind(getItem(position))

    class Holder(private val binding: ItemLogBinding) : RecyclerView.ViewHolder(binding.root) {

        private val clock = SimpleDateFormat("dd/MM HH:mm:ss", Locale.getDefault())

        fun bind(entry: LogEntry) {
            val context = binding.root.context

            val (label, colour) = when (entry.level) {
                Level.SEND -> context.getString(R.string.lvl_send) to R.color.lvl_send
                Level.ERROR -> context.getString(R.string.lvl_error) to R.color.lvl_error
                Level.WARN -> context.getString(R.string.lvl_warn) to R.color.lvl_warn
                else -> context.getString(R.string.lvl_info) to R.color.lvl_info
            }
            binding.logLevel.text = label
            binding.logLevel.setTextColor(ContextCompat.getColor(context, colour))
            binding.logTime.text = clock.format(Date(entry.timestamp))

            binding.logPhone.apply {
                text = entry.phone ?: ""
                visibility = if (entry.phone.isNullOrBlank()) android.view.View.GONE
                else android.view.View.VISIBLE
            }

            binding.logBody.apply {
                text = entry.body ?: ""
                visibility = if (entry.body.isNullOrBlank()) android.view.View.GONE
                else android.view.View.VISIBLE
            }

            binding.logDetail.apply {
                text = entry.detail ?: ""
                visibility = if (entry.detail.isNullOrBlank()) android.view.View.GONE
                else android.view.View.VISIBLE
            }
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<LogEntry>() {
            override fun areItemsTheSame(a: LogEntry, b: LogEntry) = a.id == b.id
            override fun areContentsTheSame(a: LogEntry, b: LogEntry) = a == b
        }
    }
}
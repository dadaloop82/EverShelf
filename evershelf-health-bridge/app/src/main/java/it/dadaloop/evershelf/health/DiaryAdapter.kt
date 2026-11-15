package it.dadaloop.evershelf.health

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import it.dadaloop.evershelf.health.databinding.ItemDiaryDayBinding
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

/** One row per stored day, oldest state included: sent, queued or local only. */
class DiaryAdapter(
    private val locale: () -> Locale,
    private val onOpen: (DayRow) -> Unit,
    private val onSend: (DayRow) -> Unit,
) : RecyclerView.Adapter<DiaryAdapter.VH>() {

    private val items = mutableListOf<DayRow>()

    fun submit(list: List<DayRow>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemDiaryDayBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun getItemCount(): Int = items.size

    override fun onBindViewHolder(holder: VH, position: Int) {
        holder.bind(items[position])
    }

    inner class VH(private val binding: ItemDiaryDayBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(row: DayRow) {
            val ctx = binding.root.context
            val loc = locale()
            val date = runCatching { LocalDate.parse(row.date) }.getOrNull()
            binding.dayTitle.text = date?.format(DateTimeFormatter.ofPattern("EEEE d MMMM yyyy", loc))
                ?: row.date

            val json = row.obj()
            val parts = mutableListOf<String>()
            json.optLong("steps", 0L).takeIf { it > 0 }?.let { parts.add("👟 $it") }
            json.optInt("burned_kcal", json.optInt("active_kcal", 0)).takeIf { it > 0 }?.let { parts.add("🔥 $it kcal") }
            json.optDouble("sleep_hours", 0.0).takeIf { it > 0 }?.let {
                parts.add("😴 " + String.format(loc, "%.1f h", it))
            }
            json.optDouble("weight_kg", 0.0).takeIf { it > 0 }?.let {
                parts.add("⚖️ " + String.format(loc, "%.1f kg", it))
            }
            binding.dayMetrics.text = if (parts.isEmpty()) ctx.getString(R.string.dash_no_data) else parts.joinToString("  ·  ")

            binding.dayChip.text = when {
                row.pending -> ctx.getString(R.string.diary_pending)
                row.localOnly -> ctx.getString(R.string.diary_local)
                else -> ctx.getString(R.string.diary_synced)
            }
            binding.dayChip.setBackgroundResource(
                when {
                    row.pending -> R.drawable.bg_chip_amber
                    row.localOnly -> R.drawable.bg_chip_neutral
                    else -> R.drawable.bg_chip_mint
                }
            )

            binding.dayNote.text = row.note
            binding.dayNote.visibility = if (row.note.isBlank()) View.GONE else View.VISIBLE
            binding.daySend.visibility = if (row.pending) View.VISIBLE else View.GONE

            binding.dayCard.setOnClickListener { onOpen(row) }
            binding.daySend.setOnClickListener { onSend(row) }
        }
    }
}

package it.dadaloop.evershelf.health

import android.os.Bundle
import android.text.InputType
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.EditorInfo
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import it.dadaloop.evershelf.health.databinding.FragmentDiaryBinding
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.format.DateTimeFormatter

/** Every day the bridge has seen, with its note and its queue state. */
class DiaryFragment : Fragment() {

    private var _binding: FragmentDiaryBinding? = null
    private val binding get() = _binding!!
    private lateinit var adapter: DiaryAdapter

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, state: Bundle?): View {
        _binding = FragmentDiaryBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        adapter = DiaryAdapter(
            locale = { LocaleHelper.locale(requireContext()) },
            onOpen = { showDay(it) },
            onSend = { sendOne(it) },
        )
        binding.diaryList.layoutManager = LinearLayoutManager(requireContext())
        binding.diaryList.adapter = adapter
        binding.swDiary.setOnRefreshListener {
            lifecycleScope.launch {
                binding.diaryProgress.visibility = View.VISIBLE
                SyncEngine.backfill(requireContext(), 7)
                load()
                binding.diaryProgress.visibility = View.GONE
                binding.swDiary.isRefreshing = false
                Toast.makeText(requireContext(), R.string.diary_refresh_done, Toast.LENGTH_SHORT).show()
            }
        }
    }

    override fun onResume() {
        super.onResume()
        if (_binding != null) load()
    }

    private fun load() {
        val rows = HealthDb(requireContext()).recentDays(400)
        adapter.submit(rows)
        binding.diaryEmpty.visibility = if (rows.isEmpty()) View.VISIBLE else View.GONE
        binding.diaryList.visibility = if (rows.isEmpty()) View.GONE else View.VISIBLE
    }

    private fun sendOne(row: DayRow) {
        lifecycleScope.launch {
            val res = SyncEngine.sendDay(requireContext(), row.date)
            val text = res.fold(
                onSuccess = { getString(R.string.diary_send_ok) },
                onFailure = { getString(R.string.diary_send_fail, it.message ?: getString(R.string.err_generic)) },
            )
            Toast.makeText(requireContext(), text, Toast.LENGTH_LONG).show()
            load()
        }
    }

    /** The day's own sheet: every metric it holds, the note, and a way to send it. */
    private fun showDay(row: DayRow) {
        val ctx = requireContext()
        val loc = LocaleHelper.locale(ctx)
        val scroll = ScrollView(ctx)
        val box = LinearLayout(ctx).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(20), dp(8), dp(20), dp(8))
        }
        scroll.addView(box)

        val json = row.obj()
        val date = runCatching { LocalDate.parse(row.date) }.getOrNull()
            ?.format(DateTimeFormatter.ofPattern("EEEE d MMMM yyyy", loc)) ?: row.date
        box.addView(label("$date", 17f, true))
        box.addView(label(ctx.getString(R.string.diary_hint), 12f, false))

        for (metric in Metrics.ALL) {
            val value = Metrics.value(row, metric) ?: continue
            val text = if (metric.decimals == 0) value.toLong().toString()
            else String.format(loc, "%.${metric.decimals}f", value)
            // A pressure needs BOTH numbers: "120 mmHg" alone is half a reading.
            val second = Metrics.secondValue(row, metric)
            val shown = if (second != null) "${text}/${second.toLong()} ${metric.unit}".trim()
            else "$text ${metric.unit}".trim()
            box.addView(metricLine(getString(metric.labelRes), shown))
        }

        val note = EditText(ctx).apply {
            hint = getString(R.string.diary_note_hint)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_SENTENCES or InputType.TYPE_TEXT_FLAG_MULTI_LINE
            setText(row.note)
            imeOptions = EditorInfo.IME_ACTION_DONE
            setPadding(dp(8), dp(10), dp(8), dp(10))
        }
        box.addView(note)

        AlertDialog.Builder(ctx)
            .setTitle(R.string.diary_detail_title)
            .setView(scroll)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                HealthDb(ctx).setNote(row.date, note.text.toString().trim())
                Toast.makeText(ctx, R.string.diary_note_saved, Toast.LENGTH_SHORT).show()
                load()
            }
            .setNeutralButton(R.string.diary_send) { _, _ ->
                HealthDb(ctx).setNote(row.date, note.text.toString().trim())
                sendOne(row)
            }
            .setNegativeButton(R.string.btn_close, null)
            .show()
    }

    private fun metricLine(label: String, value: String): View {
        val ctx = requireContext()
        val row = LinearLayout(ctx).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(0, dp(4), 0, dp(4))
        }
        val name = TextView(ctx).apply {
            text = label
            textSize = 13f
            setTextColor(requireContext().getColor(R.color.es_text_soft))
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f)
        }
        val valView = TextView(ctx).apply {
            text = value
            textSize = 13f
            setTypeface(typeface, android.graphics.Typeface.BOLD)
            setTextColor(requireContext().getColor(R.color.es_text))
        }
        row.addView(name)
        row.addView(valView)
        return row
    }

    private fun label(text: String, size: Float, bold: Boolean): TextView {
        return TextView(requireContext()).apply {
            this.text = text
            textSize = size
            if (bold) setTypeface(typeface, android.graphics.Typeface.BOLD)
            setTextColor(requireContext().getColor(if (bold) R.color.es_text else R.color.es_muted))
        }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}

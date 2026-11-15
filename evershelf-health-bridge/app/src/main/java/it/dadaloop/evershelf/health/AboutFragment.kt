package it.dadaloop.evershelf.health

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import it.dadaloop.evershelf.health.databinding.FragmentAboutBinding
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Identity, updates, diagnostics: everything a self-hosted household needs to trust it. */
class AboutFragment : Fragment() {

    private var _binding: FragmentAboutBinding? = null
    private val binding get() = _binding!!
    private var available: Updater.UpdateInfo? = null

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, state: Bundle?): View {
        _binding = FragmentAboutBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val ctx = requireContext()

        binding.aboutVersion.text = getString(R.string.about_version, AppInfo.versionName, AppInfo.versionCode)
        binding.aboutRepoRow.text = "github.com/${AppInfo.REPO_OWNER}/${AppInfo.REPO_NAME}"
        binding.aboutAuthorValue.text = AppInfo.AUTHOR
        binding.aboutLicenseValue.text = "${AppInfo.LICENSE} · ${getString(R.string.app_name)}"
        binding.aboutRepoRow.setOnClickListener { openUrl(AppInfo.REPO_URL) }
        binding.aboutAuthorValue.setOnClickListener { openUrl(AppInfo.REPO_URL) }

        binding.swAutoReport.isChecked = Prefs.autoReport(ctx)
        binding.swAutoReport.setOnCheckedChangeListener { _, checked -> Prefs.setAutoReport(ctx, checked) }

        binding.aboutUpdateCheck.setOnClickListener { checkUpdate() }
        binding.aboutUpdateInstall.setOnClickListener { installUpdate() }

        binding.btnSendReport.setOnClickListener {
            val ctx2 = requireContext()
            Toast.makeText(ctx2, R.string.about_checking, Toast.LENGTH_SHORT).show()
            lifecycleScope.launch {
                val ok = withContext(Dispatchers.IO) {
                    ErrorReporter.reportNow(ctx2, "Manual report from the Info panel")
                }
                val text = if (ok) {
                    getString(R.string.about_report_sent)
                } else {
                    getString(R.string.about_report_fail, getString(R.string.err_unreachable))
                }
                Toast.makeText(requireContext(), text, Toast.LENGTH_LONG).show()
            }
        }
        binding.btnShowLogs.setOnClickListener { Ui.showLog(ctx) }
        binding.btnShareLogs.setOnClickListener { Logs.share(ctx) }
        binding.btnClearLogs.setOnClickListener {
            Logs.clear(ctx)
            Toast.makeText(ctx, R.string.about_logs_cleared, Toast.LENGTH_SHORT).show()
        }

        // A pending update saved by a previous session is shown without a network round-trip.
        val pending = Prefs.pendingUpdateVersion(ctx)
        if (pending.isNotBlank()) {
            binding.aboutUpdateState.text = getString(R.string.about_update_available, pending)
            binding.aboutUpdateInstall.visibility = View.VISIBLE
        }
    }

    private fun checkUpdate() {
        val ctx = requireContext()
        binding.aboutUpdateCheck.isEnabled = false
        binding.aboutUpdateState.setText(R.string.about_checking)
        lifecycleScope.launch {
            val info = runCatching { Updater.check(ctx, force = true) }.getOrNull()
            if (_binding == null) return@launch
            binding.aboutUpdateCheck.isEnabled = true
            available = info
            if (info == null) {
                binding.aboutUpdateState.setText(R.string.about_up_to_date)
                binding.aboutUpdateInstall.visibility = View.GONE
            } else {
                binding.aboutUpdateState.text = getString(R.string.about_update_available, info.version)
                binding.aboutUpdateInstall.visibility = View.VISIBLE
                Prefs.setPendingUpdateVersion(ctx, info.version)
            }
        }
    }

    private fun installUpdate() {
        val ctx = requireContext()
        val info = available
        if (info == null) {
            checkUpdate()
            return
        }
        if (!Updater.canInstallPackages(ctx)) {
            Toast.makeText(ctx, R.string.about_update_perm, Toast.LENGTH_LONG).show()
            Updater.openInstallPermission(requireActivity())
            return
        }
        binding.aboutUpdateInstall.isEnabled = false
        binding.aboutUpdateProgress.visibility = View.VISIBLE
        binding.aboutUpdateProgress.progress = 0
        binding.aboutUpdateState.setText(R.string.install_downloading)
        lifecycleScope.launch {
            val result = Updater.download(ctx, info) { pct ->
                activity?.runOnUiThread { if (_binding != null) binding.aboutUpdateProgress.progress = pct.coerceAtLeast(0) }
            }
            if (_binding == null) return@launch
            result.fold(
                onSuccess = { file ->
                    binding.aboutUpdateState.setText(R.string.about_update_install)
                    Updater.startInstall(ctx, file).fold(
                        onSuccess = { binding.aboutUpdateProgress.visibility = View.GONE },
                        onFailure = { e ->
                            binding.aboutUpdateProgress.visibility = View.GONE
                            binding.aboutUpdateInstall.isEnabled = true
                            binding.aboutUpdateState.text = getString(R.string.about_update_error, e.message ?: "")
                        },
                    )
                },
                onFailure = { e ->
                    binding.aboutUpdateProgress.visibility = View.GONE
                    binding.aboutUpdateInstall.isEnabled = true
                    binding.aboutUpdateState.text = getString(R.string.about_update_error, e.message ?: "")
                },
            )
        }
    }

    private fun openUrl(url: String) {
        runCatching {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
        }.onFailure {
            Toast.makeText(requireContext(), R.string.err_generic, Toast.LENGTH_SHORT).show()
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}

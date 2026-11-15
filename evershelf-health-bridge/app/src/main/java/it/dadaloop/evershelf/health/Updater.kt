package it.dadaloop.evershelf.health

import android.app.Activity
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * OTA, the way EverShelf does it: ask the LAN server first (a self-hosted install may have no
 * internet at all), fall back to the GitHub release, and install ONLY an APK signed with the
 * same key as the running app.
 */
object Updater {

    data class UpdateInfo(
        val version: String,
        val versionCode: Long,
        val url: String,
        val source: String,
    )

    private const val CHECK_THROTTLE_MS = 6 * 60 * 60 * 1000L

    suspend fun check(ctx: Context, force: Boolean = false): UpdateInfo? {
        val now = System.currentTimeMillis()
        if (!force && now - Prefs.lastUpdateCheck(ctx) < CHECK_THROTTLE_MS) return null
        Prefs.setLastUpdateCheck(ctx, now)

        // 1) The server knows the release it hosts (works with no internet).
        if (Prefs.isConfigured(ctx)) {
            val lan = EverShelfClient.lanUpdate(Prefs.url(ctx))
            if (lan != null) {
                val version = lan.optString("version", "")
                val code = lan.optLong("version_code", 0L)
                val apk = lan.optString("apk_url", "")
                if (apk.isNotEmpty() && isNewer(version, code)) {
                    Logs.info(ctx, "update", "LAN offers $version ($code)")
                    return UpdateInfo(version, code, apk, "lan")
                }
                return null
            }
        }

        // 2) GitHub release (the CI publishes `health-bridge-latest`).
        val release = EverShelfClient.githubLatest() ?: return null
        val tag = release.optString("tag_name", "")
        val version = tag.removePrefix("health-bridge-").removePrefix("v")
        if (version.isBlank() || !isNewer(version, 0L)) return null
        val assets = release.optJSONArray("assets")
        var url = ""
        if (assets != null) {
            for (i in 0 until assets.length()) {
                val a = assets.optJSONObject(i) ?: continue
                val name = a.optString("name", "")
                val candidate = a.optString("browser_download_url", "")
                if (name.endsWith(".apk", true) && containsRepoUrl(candidate)) {
                    url = candidate
                    break
                }
            }
        }
        if (url.isBlank()) url = AppInfo.APK_URL
        Logs.info(ctx, "update", "GitHub offers $version")
        return UpdateInfo(version, 0L, url, "github")
    }

    /**
     * The asset URL must belong to OUR repository: the API answer is data, and a redirect to
     * somewhere else must not become an installer prompt.
     */
    private fun containsRepoUrl(url: String): Boolean =
        url.startsWith("https://github.com/${AppInfo.REPO_OWNER}/${AppInfo.REPO_NAME}/") ||
            url.startsWith("https://objects.githubusercontent.com/")

    fun isNewer(remoteVersion: String, remoteCode: Long): Boolean {
        if (remoteCode > 0L && remoteCode > AppInfo.versionCode.toLong()) return true
        if (remoteVersion.isBlank()) return false
        val remote = remoteVersion.split('.').map { it.filter(Char::isDigit).toIntOrNull() ?: 0 }
        val local = AppInfo.versionName.split('.').map { it.filter(Char::isDigit).toIntOrNull() ?: 0 }
        for (i in 0 until maxOf(remote.size, local.size)) {
            val r = remote.getOrElse(i) { 0 }
            val l = local.getOrElse(i) { 0 }
            if (r != l) return r > l
        }
        return false
    }

    fun canInstallPackages(ctx: Context): Boolean = try {
        ctx.packageManager.canRequestPackageInstalls()
    } catch (_: Throwable) {
        true
    }

    fun openInstallPermission(activity: Activity) {
        try {
            activity.startActivity(
                Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${activity.packageName}"))
            )
        } catch (_: Throwable) {
            runCatching { activity.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES)) }
        }
    }

    /**
     * Downloads and proves the file is OUR next version: a ZIP header, our package name, a
     * higher versionCode and the SAME signing certificate. Anything else is deleted and
     * reported — an update is the one download that ends in an installer.
     */
    suspend fun download(ctx: Context, info: UpdateInfo, onProgress: (Int) -> Unit = {}): Result<File> {
        val dir = ctx.getExternalFilesDir(null) ?: ctx.filesDir
        val dest = File(dir, "evershelf-health-bridge-update.apk")
        if (!EverShelfClient.download(info.url, dest, onProgress)) {
            ErrorReporter.report(ctx, "install_download_failed", "Download failed: ${info.url}", mapOf("version" to info.version))
            return Result.failure(IllegalStateException("download_failed"))
        }
        if (!dest.exists() || dest.length() < 1024) {
            dest.delete()
            return Result.failure(IllegalStateException("file_missing"))
        }
        val zip = runCatching {
            dest.inputStream().use { s -> val b = ByteArray(2); s.read(b); b[0] == 'P'.code.toByte() && b[1] == 'K'.code.toByte() }
        }.getOrDefault(false)
        if (!zip) {
            dest.delete()
            return Result.failure(IllegalStateException("not_an_apk"))
        }
        val pm = ctx.packageManager
        val archive = runCatching {
            pm.getPackageArchiveInfo(dest.absolutePath, PackageManager.GET_SIGNING_CERTIFICATES)
        }.getOrNull()
        if (archive == null || archive.packageName != ctx.packageName) {
            dest.delete()
            ErrorReporter.report(ctx, "install_wrong_package", "APK package=${archive?.packageName} expected=${ctx.packageName}")
            return Result.failure(IllegalStateException("wrong_package"))
        }
        val archiveCode = if (Build.VERSION.SDK_INT >= 28) archive.longVersionCode else 0L
        if (archiveCode > 0 && archiveCode <= AppInfo.versionCode) {
            dest.delete()
            return Result.failure(IllegalStateException("not_newer"))
        }
        val installedSigner = signerFingerprint(ctx, null)
        val archiveSigner = signerFingerprint(ctx, dest.absolutePath)
        if (installedSigner.isBlank() || archiveSigner.isBlank() || installedSigner != archiveSigner) {
            dest.delete()
            ErrorReporter.report(
                ctx, "install_signer_mismatch",
                "APK signer does not match the installed app",
                mapOf("apk_signer" to archiveSigner, "installed_signer" to installedSigner),
                level = "error"
            )
            return Result.failure(IllegalStateException("signer_mismatch"))
        }
        return Result.success(dest)
    }

    private fun signerFingerprint(ctx: Context, apkPath: String?): String {
        return try {
            val pm = ctx.packageManager
            val info = if (apkPath == null) {
                pm.getPackageInfo(ctx.packageName, PackageManager.GET_SIGNING_CERTIFICATES)
            } else {
                pm.getPackageArchiveInfo(apkPath, PackageManager.GET_SIGNING_CERTIFICATES) ?: return ""
            }
            val signers = info.signingInfo?.apkContentsSigners ?: return ""
            val cert = signers.firstOrNull()?.toByteArray() ?: return ""
            MessageDigest.getInstance("SHA-256").digest(cert).joinToString("") { "%02x".format(it) }
        } catch (_: Throwable) {
            ""
        }
    }

    /** Hands the verified APK to PackageInstaller (the path Android 14+ accepts for self-update). */
    fun startInstall(ctx: Context, file: File): Result<Unit> {
        return try {
            val pi = ctx.packageManager.packageInstaller
            val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                params.setInstallReason(PackageManager.INSTALL_REASON_USER)
            }
            val sessionId = pi.createSession(params)
            val session = pi.openSession(sessionId)
            try {
                file.inputStream().use { input ->
                    session.openWrite("package", 0, file.length()).use { out ->
                        input.copyTo(out)
                        session.fsync(out)
                    }
                }
                val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    PendingIntent.FLAG_MUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
                } else {
                    PendingIntent.FLAG_UPDATE_CURRENT
                }
                val resultIntent = Intent(ctx, InstallResultReceiver::class.java)
                    .putExtra(InstallResultReceiver.EXTRA_SESSION, sessionId)
                val pending = PendingIntent.getBroadcast(ctx, sessionId, resultIntent, flags)
                session.commit(pending.intentSender)
            } catch (e: Throwable) {
                runCatching { session.abandon() }
                throw e
            }
            Logs.info(ctx, "update", "installer session $sessionId started for ${file.length()} bytes")
            Result.success(Unit)
        } catch (e: Throwable) {
            ErrorReporter.report(ctx, "install_failed", "PackageInstaller refused the APK: ${e.message}", level = "error")
            Result.failure(e)
        }
    }

    /** Used by the Settings row: the JSON of the last check, for the Info panel. */
    fun describe(info: UpdateInfo?): String = info?.let { JSONObject().put("version", it.version).put("source", it.source).toString() } ?: ""
}

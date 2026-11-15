package it.dadaloop.evershelf.health

/** Identity of this project, shown by the Info panel and used by the OTA check. */
object AppInfo {
    const val PROJECT = "EverShelf Health Bridge"
    const val AUTHOR = "dadaloop82"
    const val LICENSE = "MIT"
    const val REPO_OWNER = BuildConfig.GITHUB_OWNER
    const val REPO_NAME = BuildConfig.GITHUB_REPO
    const val RELEASE_TAG = BuildConfig.RELEASE_TAG
    val REPO_URL = "https://github.com/$REPO_OWNER/$REPO_NAME"
    val RELEASE_URL = "$REPO_URL/releases/tag/$RELEASE_TAG"
    /** Direct asset URL used when the GitHub API cannot be reached. */
    val APK_URL = "$REPO_URL/releases/download/$RELEASE_TAG/evershelf-health-bridge.apk"

    val versionName: String get() = BuildConfig.VERSION_NAME
    val versionCode: Int get() = BuildConfig.VERSION_CODE
}

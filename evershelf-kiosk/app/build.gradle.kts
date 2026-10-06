import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// ── Signing credentials ─────────────────────────────────────────────────────
// Never hardcode secrets. Provide them (gitignored) via keystore.properties at
// the project root, or via environment variables (used by CI from GitHub Secrets):
//   KIOSK_STORE_FILE / KIOSK_STORE_PASSWORD / KIOSK_KEY_ALIAS / KIOSK_KEY_PASSWORD
// If nothing is configured the default Android debug keystore is used so local
// `assembleDebug` keeps working. CI creates that keystore when secrets are absent.
val keystoreProps = Properties().apply {
    val f = rootProject.file("keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}
fun signingValue(propKey: String, envKey: String): String? =
    keystoreProps.getProperty(propKey) ?: System.getenv(envKey)

android {
    namespace = "it.dadaloop.evershelf.kiosk"
    compileSdk = 35

    defaultConfig {
        applicationId = "it.dadaloop.evershelf.kiosk"
        minSdk = 24
        targetSdk = 35
        versionCode = 22
        versionName = "1.7.21"
    }

    signingConfigs {
        create("project") {
            val storePath = signingValue("storeFile", "KIOSK_STORE_FILE")
                ?: if (rootProject.file("evershelf.jks").exists()) "evershelf.jks" else null
            val storePass = signingValue("storePassword", "KIOSK_STORE_PASSWORD")
            if (storePath != null && storePass != null) {
                storeFile = file(storePath)
                storePassword = storePass
                keyAlias = signingValue("keyAlias", "KIOSK_KEY_ALIAS")
                keyPassword = signingValue("keyPassword", "KIOSK_KEY_PASSWORD")
            } else {
                storeFile = file(System.getProperty("user.home") + "/.android/debug.keystore")
                storePassword = "android"
                keyAlias = "androiddebugkey"
                keyPassword = "android"
            }
        }
    }

    buildTypes {
        debug {
            signingConfig = signingConfigs.getByName("project")
        }
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("project")
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"))
        }
    }

    buildFeatures {
        viewBinding = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}

dependencies {
    // core-ktx ≥ 1.16 needs AGP 9.1 + compileSdk 37 — keep the last line that fits AGP 8.5 / SDK 35
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.8.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.webkit:webkit:1.10.0")
    implementation("androidx.recyclerview:recyclerview:1.3.2")
    implementation("org.java-websocket:Java-WebSocket:1.5.5")
}

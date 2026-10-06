import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// ── Signing credentials ─────────────────────────────────────────────────────
// Never hardcode secrets. Provide them (gitignored) via keystore.properties at
// the project root, or via environment variables (used by CI from GitHub Secrets):
//   KIOSK_STORE_FILE / KIOSK_STORE_PASSWORD / KIOSK_KEY_ALIAS / KIOSK_KEY_PASSWORD
// If nothing is configured the default Android debug keystore is used so local
// `assembleDebug` keeps working.
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
        versionCode = 21
        versionName = "1.7.20"
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
                // No project keystore configured — use the standard debug keystore.
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
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }
    kotlinOptions {
        jvmTarget = "1.8"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.19.1")
    implementation("androidx.appcompat:appcompat:1.6.1")
    implementation("com.google.android.material:material:1.14.0")
    implementation("androidx.constraintlayout:constraintlayout:2.2.2")
    implementation("androidx.webkit:webkit:1.10.0")
    implementation("androidx.recyclerview:recyclerview:1.3.2")
    implementation("org.java-websocket:Java-WebSocket:1.5.5")
}

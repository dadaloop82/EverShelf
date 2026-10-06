import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// ── Signing credentials (see evershelf-kiosk/app/build.gradle.kts) ──────────
// keystore.properties at the project root, or env vars:
//   HEALTH_STORE_FILE / HEALTH_STORE_PASSWORD / HEALTH_KEY_ALIAS / HEALTH_KEY_PASSWORD
val keystoreProps = Properties().apply {
    val f = rootProject.file("keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}
fun signingValue(propKey: String, envKey: String): String? =
    keystoreProps.getProperty(propKey) ?: System.getenv(envKey)

android {
    namespace = "it.dadaloop.evershelf.health"
    compileSdk = 35

    defaultConfig {
        applicationId = "it.dadaloop.evershelf.health"
        minSdk = 28
        targetSdk = 35
        versionCode = 6
        versionName = "1.0.5"
    }

    signingConfigs {
        create("project") {
            val storePath = signingValue("storeFile", "HEALTH_STORE_FILE")
                ?: if (rootProject.file("evershelf.jks").exists()) "evershelf.jks" else null
            val storePass = signingValue("storePassword", "HEALTH_STORE_PASSWORD")
            if (storePath != null && storePass != null) {
                storeFile = file(storePath)
                storePassword = storePass
                keyAlias = signingValue("keyAlias", "HEALTH_KEY_ALIAS")
                keyPassword = signingValue("keyPassword", "HEALTH_KEY_PASSWORD")
            } else {
                // CI must create this before assemble (see build-health-bridge.yml).
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

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    buildFeatures {
        viewBinding = true
    }
    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.coordinatorlayout:coordinatorlayout:1.2.0")
    implementation("androidx.activity:activity-ktx:1.9.2")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.6")
    implementation("androidx.work:work-runtime-ktx:2.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")
    // 1.2.x needs AGP 9.1 + compileSdk 37 — stay on the alpha that matches AGP 8.5 / SDK 35
    implementation("androidx.health.connect:connect-client:1.1.0-alpha11")
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")
}

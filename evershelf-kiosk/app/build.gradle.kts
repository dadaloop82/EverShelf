plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "it.dadaloop.evershelf.kiosk"
    compileSdk = 34

    defaultConfig {
        applicationId = "it.dadaloop.evershelf.kiosk"
        minSdk = 24
        targetSdk = 34
        versionCode = 17
        versionName = "1.7.16"
    }

    signingConfigs {
        // Project keystore — same on every machine so OTA updates always work.
        create("project") {
            storeFile = file("../evershelf.jks")
            storePassword = "evershelf123"
            keyAlias = "evershelf"
            keyPassword = "evershelf123"
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
    implementation("androidx.core:core-ktx:1.12.0")
    implementation("androidx.appcompat:appcompat:1.6.1")
    implementation("com.google.android.material:material:1.11.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.webkit:webkit:1.10.0")
    implementation("androidx.recyclerview:recyclerview:1.3.2")
    implementation("org.java-websocket:Java-WebSocket:1.5.5")
}

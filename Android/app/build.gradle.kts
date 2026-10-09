plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "pt.smsapi.gateway"
    compileSdk = 36

    defaultConfig {
        applicationId = "pt.smsapi.gateway"
        minSdk = 24
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"
        // Only the languages we actually ship. Without this the APK also carries
        // every locale bundled with AppCompat and Material.
        resourceConfigurations += listOf("pt", "es", "en", "fr")
    }

    signingConfigs {
        create("release") {
            storeFile = file("keystore/release.jks")
            storePassword = project.findProperty("RELEASE_STORE_PASSWORD") as String? ?: "smsapi-release"
            keyAlias = project.findProperty("RELEASE_KEY_ALIAS") as String? ?: "smsapi"
            keyPassword = project.findProperty("RELEASE_KEY_PASSWORD") as String? ?: "smsapi-release"
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            signingConfig = signingConfigs.getByName("release")
        }
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    packaging {
        resources.excludes += setOf("META-INF/*.kotlin_module", "DebugProbesKt.bin")
    }

    buildFeatures {
        viewBinding = true
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.recyclerview:recyclerview:1.3.2")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
}
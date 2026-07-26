package it.dadaloop.evershelf.health

import android.content.Context
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.ActiveCaloriesBurnedRecord
import androidx.health.connect.client.records.DistanceRecord
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.FloorsClimbedRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.HydrationRecord
import androidx.health.connect.client.records.RestingHeartRateRecord
import androidx.health.connect.client.records.SleepSessionRecord
import androidx.health.connect.client.records.StepsRecord
import androidx.health.connect.client.records.TotalCaloriesBurnedRecord
import androidx.health.connect.client.records.WeightRecord
import androidx.health.connect.client.request.AggregateRequest
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.ZonedDateTime
import kotlin.math.max
import kotlin.math.roundToInt
import kotlin.math.roundToLong

object HealthConnectReader {

    val PERMISSIONS: Set<String> = setOf(
        HealthPermission.getReadPermission(StepsRecord::class),
        HealthPermission.getReadPermission(ActiveCaloriesBurnedRecord::class),
        HealthPermission.getReadPermission(TotalCaloriesBurnedRecord::class),
        HealthPermission.getReadPermission(ExerciseSessionRecord::class),
        HealthPermission.getReadPermission(SleepSessionRecord::class),
        HealthPermission.getReadPermission(WeightRecord::class),
        HealthPermission.getReadPermission(HydrationRecord::class),
        HealthPermission.getReadPermission(HeartRateRecord::class),
        HealthPermission.getReadPermission(RestingHeartRateRecord::class),
        HealthPermission.getReadPermission(DistanceRecord::class),
        HealthPermission.getReadPermission(FloorsClimbedRecord::class),
    )

    fun sdkStatus(ctx: Context): Int = HealthConnectClient.getSdkStatus(ctx)

    fun clientOrNull(ctx: Context): HealthConnectClient? {
        return if (sdkStatus(ctx) == HealthConnectClient.SDK_AVAILABLE) {
            HealthConnectClient.getOrCreate(ctx)
        } else null
    }

    suspend fun readToday(ctx: Context): JSONObject = withContext(Dispatchers.IO) {
        val zone = ZoneId.systemDefault()
        val startOfDay = LocalDate.now(zone).atStartOfDay(zone).toInstant()
        val now = Instant.now()
        val out = JSONObject()
            .put("date", LocalDate.now(zone).toString())
            .put("source", "health_connect")
            .put("synced_at", ZonedDateTime.now(zone).toOffsetDateTime().toString())

        val client = clientOrNull(ctx)
        if (client == null) {
            out.put("error", "health_connect_unavailable")
            return@withContext out
        }

        val range = TimeRangeFilter.between(startOfDay, now)

        try {
            val stepsAgg = client.aggregate(
                AggregateRequest(setOf(StepsRecord.COUNT_TOTAL), timeRangeFilter = range)
            )
            stepsAgg[StepsRecord.COUNT_TOTAL]?.let { out.put("steps", it) }
        } catch (_: Exception) { }

        try {
            val activeAgg = client.aggregate(
                AggregateRequest(setOf(ActiveCaloriesBurnedRecord.ACTIVE_CALORIES_TOTAL), timeRangeFilter = range)
            )
            activeAgg[ActiveCaloriesBurnedRecord.ACTIVE_CALORIES_TOTAL]?.inKilocalories?.let {
                out.put("active_kcal", it.roundToInt())
            }
        } catch (_: Exception) { }

        try {
            val totalAgg = client.aggregate(
                AggregateRequest(setOf(TotalCaloriesBurnedRecord.ENERGY_TOTAL), timeRangeFilter = range)
            )
            totalAgg[TotalCaloriesBurnedRecord.ENERGY_TOTAL]?.inKilocalories?.let {
                out.put("burned_kcal", it.roundToInt())
            }
        } catch (_: Exception) { }

        if (!out.has("burned_kcal") && out.has("active_kcal")) {
            out.put("burned_kcal", out.getInt("active_kcal"))
        }

        // Formal workouts (Google Fit often leaves this empty for casual walking)
        var sessionMin = 0L
        val types = linkedSetOf<String>()
        try {
            val sessions = client.readRecords(
                ReadRecordsRequest(ExerciseSessionRecord::class, timeRangeFilter = range)
            ).records
            for (s in sessions) {
                sessionMin += java.time.Duration.between(s.startTime, s.endTime).toMinutes()
                types.add(exerciseTypeLabel(s.exerciseType, s.title))
            }
        } catch (_: Exception) { }

        // Casual movement: estimate minutes from steps / active kcal when no workout logged
        val steps = if (out.has("steps")) out.getLong("steps") else 0L
        val activeKcal = if (out.has("active_kcal")) out.getInt("active_kcal") else 0
        val fromSteps = (steps / 100L).coerceAtMost(180L) // ~100 steps ≈ 1 min walking
        val fromActive = if (activeKcal > 0) (activeKcal / 5.0).roundToLong().coerceAtMost(180L) else 0L
        val estimatedMove = max(fromSteps, fromActive)
        val exerciseMin = if (sessionMin > 0) sessionMin else estimatedMove
        if (exerciseMin > 0) {
            out.put("exercise_min", exerciseMin.toInt())
            if (types.isEmpty() && sessionMin == 0L && estimatedMove > 0) {
                types.add("walking")
            }
            if (types.isNotEmpty()) {
                val arr = JSONArray()
                types.forEach { arr.put(it) }
                out.put("exercise_types", arr)
            }
        }

        try {
            val distAgg = client.aggregate(
                AggregateRequest(setOf(DistanceRecord.DISTANCE_TOTAL), timeRangeFilter = range)
            )
            distAgg[DistanceRecord.DISTANCE_TOTAL]?.inMeters?.let {
                out.put("distance_m", it.roundToInt())
            }
        } catch (_: Exception) { }

        try {
            val floorsAgg = client.aggregate(
                AggregateRequest(setOf(FloorsClimbedRecord.FLOORS_CLIMBED_TOTAL), timeRangeFilter = range)
            )
            floorsAgg[FloorsClimbedRecord.FLOORS_CLIMBED_TOTAL]?.let {
                out.put("floors", it.roundToInt())
            }
        } catch (_: Exception) { }

        try {
            val sleeps = client.readRecords(
                ReadRecordsRequest(SleepSessionRecord::class, timeRangeFilter = range)
            ).records
            var minutes = 0L
            for (s in sleeps) {
                minutes += java.time.Duration.between(s.startTime, s.endTime).toMinutes()
            }
            if (minutes <= 0) {
                val nightStart = LocalDate.now(zone).minusDays(1).atTime(18, 0).atZone(zone).toInstant()
                val nightEnd = LocalDate.now(zone).atTime(12, 0).atZone(zone).toInstant()
                val nightSleeps = client.readRecords(
                    ReadRecordsRequest(
                        SleepSessionRecord::class,
                        timeRangeFilter = TimeRangeFilter.between(nightStart, nightEnd)
                    )
                ).records
                for (s in nightSleeps) {
                    minutes += java.time.Duration.between(s.startTime, s.endTime).toMinutes()
                }
            }
            if (minutes > 0) {
                val hours = (minutes / 60.0 * 10.0).roundToInt() / 10.0
                out.put("sleep_hours", hours)
            }
        } catch (_: Exception) { }

        try {
            val weights = client.readRecords(
                ReadRecordsRequest(
                    WeightRecord::class,
                    timeRangeFilter = TimeRangeFilter.between(now.minusSeconds(60L * 60 * 24 * 30), now)
                )
            ).records
            weights.maxByOrNull { it.time }?.weight?.inKilograms?.let {
                out.put("weight_kg", (it * 10.0).roundToInt() / 10.0)
            }
        } catch (_: Exception) { }

        try {
            val hyd = client.aggregate(
                AggregateRequest(setOf(HydrationRecord.VOLUME_TOTAL), timeRangeFilter = range)
            )
            hyd[HydrationRecord.VOLUME_TOTAL]?.inMilliliters?.let { out.put("hydration_ml", it.roundToInt()) }
        } catch (_: Exception) { }

        try {
            val rhrAgg = client.aggregate(
                AggregateRequest(setOf(RestingHeartRateRecord.BPM_AVG), timeRangeFilter = range)
            )
            rhrAgg[RestingHeartRateRecord.BPM_AVG]?.let { out.put("resting_hr", it.roundToInt()) }
        } catch (_: Exception) {
            // Fallback: average of recent HR samples in last 24h overnight window is noisy; skip
        }

        out
    }

    private fun exerciseTypeLabel(type: Int, title: String?): String {
        val t = title?.trim().orEmpty()
        if (t.isNotEmpty()) return t.lowercase()
        return when (type) {
            ExerciseSessionRecord.EXERCISE_TYPE_RUNNING -> "running"
            ExerciseSessionRecord.EXERCISE_TYPE_WALKING -> "walking"
            ExerciseSessionRecord.EXERCISE_TYPE_BIKING -> "biking"
            ExerciseSessionRecord.EXERCISE_TYPE_BIKING_STATIONARY -> "biking"
            ExerciseSessionRecord.EXERCISE_TYPE_SWIMMING_POOL,
            ExerciseSessionRecord.EXERCISE_TYPE_SWIMMING_OPEN_WATER -> "swimming"
            ExerciseSessionRecord.EXERCISE_TYPE_STRENGTH_TRAINING -> "strength"
            ExerciseSessionRecord.EXERCISE_TYPE_YOGA -> "yoga"
            ExerciseSessionRecord.EXERCISE_TYPE_DANCING -> "dancing"
            ExerciseSessionRecord.EXERCISE_TYPE_ELLIPTICAL -> "elliptical"
            ExerciseSessionRecord.EXERCISE_TYPE_ROWING -> "rowing"
            ExerciseSessionRecord.EXERCISE_TYPE_SOCCER -> "soccer"
            ExerciseSessionRecord.EXERCISE_TYPE_TENNIS -> "tennis"
            ExerciseSessionRecord.EXERCISE_TYPE_BASKETBALL -> "basketball"
            else -> "workout"
        }
    }
}

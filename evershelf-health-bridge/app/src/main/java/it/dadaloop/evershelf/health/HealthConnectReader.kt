package it.dadaloop.evershelf.health

import android.content.Context
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.ActiveCaloriesBurnedRecord
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.HydrationRecord
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
                out.put("active_kcal", it)
            }
        } catch (_: Exception) { }

        try {
            val totalAgg = client.aggregate(
                AggregateRequest(setOf(TotalCaloriesBurnedRecord.ENERGY_TOTAL), timeRangeFilter = range)
            )
            totalAgg[TotalCaloriesBurnedRecord.ENERGY_TOTAL]?.inKilocalories?.let {
                out.put("burned_kcal", it)
            }
        } catch (_: Exception) { }

        // If only active is present, expose it also as burned for EverShelf heuristics
        if (!out.has("burned_kcal") && out.has("active_kcal")) {
            out.put("burned_kcal", out.getDouble("active_kcal"))
        }

        try {
            val sessions = client.readRecords(
                ReadRecordsRequest(ExerciseSessionRecord::class, timeRangeFilter = range)
            ).records
            var minutes = 0L
            val types = JSONArray()
            for (s in sessions) {
                minutes += java.time.Duration.between(s.startTime, s.endTime).toMinutes()
                types.put(s.exerciseType.toString())
            }
            if (minutes > 0) out.put("exercise_min", minutes)
            if (types.length() > 0) out.put("exercise_types", types)
        } catch (_: Exception) { }

        try {
            val sleeps = client.readRecords(
                ReadRecordsRequest(SleepSessionRecord::class, timeRangeFilter = range)
            ).records
            var minutes = 0L
            for (s in sleeps) {
                minutes += java.time.Duration.between(s.startTime, s.endTime).toMinutes()
            }
            if (minutes > 0) out.put("sleep_hours", minutes / 60.0)
        } catch (_: Exception) {
            // Also try previous night: yesterday 18:00 → today noon
            try {
                val nightStart = LocalDate.now(zone).minusDays(1).atTime(18, 0).atZone(zone).toInstant()
                val nightEnd = LocalDate.now(zone).atTime(12, 0).atZone(zone).toInstant()
                val sleeps = client.readRecords(
                    ReadRecordsRequest(
                        SleepSessionRecord::class,
                        timeRangeFilter = TimeRangeFilter.between(nightStart, nightEnd)
                    )
                ).records
                var minutes = 0L
                for (s in sleeps) {
                    minutes += java.time.Duration.between(s.startTime, s.endTime).toMinutes()
                }
                if (minutes > 0) out.put("sleep_hours", minutes / 60.0)
            } catch (_: Exception) { }
        }

        try {
            val weights = client.readRecords(
                ReadRecordsRequest(
                    WeightRecord::class,
                    timeRangeFilter = TimeRangeFilter.between(now.minusSeconds(60L * 60 * 24 * 30), now)
                )
            ).records
            weights.maxByOrNull { it.time }?.weight?.inKilograms?.let { out.put("weight_kg", it) }
        } catch (_: Exception) { }

        try {
            val hyd = client.aggregate(
                AggregateRequest(setOf(HydrationRecord.VOLUME_TOTAL), timeRangeFilter = range)
            )
            hyd[HydrationRecord.VOLUME_TOTAL]?.inMilliliters?.let { out.put("hydration_ml", it.toInt()) }
        } catch (_: Exception) { }

        out
    }
}

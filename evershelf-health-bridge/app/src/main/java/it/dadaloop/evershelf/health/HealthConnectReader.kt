package it.dadaloop.evershelf.health

import android.content.Context
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.ActiveCaloriesBurnedRecord
import androidx.health.connect.client.records.BasalMetabolicRateRecord
import androidx.health.connect.client.records.BloodGlucoseRecord
import androidx.health.connect.client.records.BloodPressureRecord
import androidx.health.connect.client.records.BodyFatRecord
import androidx.health.connect.client.records.BodyTemperatureRecord
import androidx.health.connect.client.records.DistanceRecord
import androidx.health.connect.client.records.ElevationGainedRecord
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.FloorsClimbedRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.HeartRateVariabilityRmssdRecord
import androidx.health.connect.client.records.HeightRecord
import androidx.health.connect.client.records.HydrationRecord
import androidx.health.connect.client.records.LeanBodyMassRecord
import androidx.health.connect.client.records.OxygenSaturationRecord
import androidx.health.connect.client.records.PowerRecord
import androidx.health.connect.client.records.RespiratoryRateRecord
import androidx.health.connect.client.records.RestingHeartRateRecord
import androidx.health.connect.client.records.SleepSessionRecord
import androidx.health.connect.client.records.SpeedRecord
import androidx.health.connect.client.records.StepsRecord
import androidx.health.connect.client.records.TotalCaloriesBurnedRecord
import androidx.health.connect.client.records.WeightRecord
import androidx.health.connect.client.aggregate.AggregateMetric
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

/**
 * Reads Health Connect and turns a DAY into one JSON snapshot.
 *
 * Every metric is read on its own and every read is wrapped: a permission the household did
 * not grant, a type the installed Health Connect does not know, or an OEM that answers
 * nothing must never lose the rest of the day (the old reader returned an almost empty
 * payload on the first failure).
 */
object HealthConnectReader {

    val PERMISSIONS: Set<String> = setOf(
        HealthPermission.getReadPermission(StepsRecord::class),
        HealthPermission.getReadPermission(ActiveCaloriesBurnedRecord::class),
        HealthPermission.getReadPermission(TotalCaloriesBurnedRecord::class),
        HealthPermission.getReadPermission(BasalMetabolicRateRecord::class),
        HealthPermission.getReadPermission(ExerciseSessionRecord::class),
        HealthPermission.getReadPermission(SleepSessionRecord::class),
        HealthPermission.getReadPermission(WeightRecord::class),
        HealthPermission.getReadPermission(HeightRecord::class),
        HealthPermission.getReadPermission(BodyFatRecord::class),
        HealthPermission.getReadPermission(LeanBodyMassRecord::class),
        HealthPermission.getReadPermission(HydrationRecord::class),
        HealthPermission.getReadPermission(HeartRateRecord::class),
        HealthPermission.getReadPermission(RestingHeartRateRecord::class),
        HealthPermission.getReadPermission(HeartRateVariabilityRmssdRecord::class),
        HealthPermission.getReadPermission(OxygenSaturationRecord::class),
        HealthPermission.getReadPermission(RespiratoryRateRecord::class),
        HealthPermission.getReadPermission(BloodPressureRecord::class),
        HealthPermission.getReadPermission(BloodGlucoseRecord::class),
        HealthPermission.getReadPermission(BodyTemperatureRecord::class),
        HealthPermission.getReadPermission(DistanceRecord::class),
        HealthPermission.getReadPermission(FloorsClimbedRecord::class),
        HealthPermission.getReadPermission(SpeedRecord::class),
        HealthPermission.getReadPermission(PowerRecord::class),
        HealthPermission.getReadPermission(ElevationGainedRecord::class),
    )

    fun sdkStatus(ctx: Context): Int = HealthConnectClient.getSdkStatus(ctx)

    fun clientOrNull(ctx: Context): HealthConnectClient? =
        if (sdkStatus(ctx) == HealthConnectClient.SDK_AVAILABLE) {
            runCatching { HealthConnectClient.getOrCreate(ctx) }.getOrNull()
        } else null

    /** Permissions Health Connect reports as granted; never throws. */
    suspend fun grantedPermissions(ctx: Context): Set<String> {
        val client = clientOrNull(ctx) ?: return emptySet()
        return runCatching { client.permissionController.getGrantedPermissions() }.getOrDefault(emptySet())
    }

    suspend fun grantedCount(ctx: Context): Int = grantedPermissions(ctx).count { PERMISSIONS.contains(it) }

    suspend fun readToday(ctx: Context): JSONObject = readDay(ctx, LocalDate.now(ZoneId.systemDefault()))

    /**
     * One day, all metrics. [date] is the household's local date; a past day is read from
     * 00:00 to 24:00, today up to now.
     */
    suspend fun readDay(ctx: Context, date: LocalDate): JSONObject = withContext(Dispatchers.IO) {
        val zone = ZoneId.systemDefault()
        val start = date.atStartOfDay(zone).toInstant()
        val dayEnd = date.plusDays(1).atStartOfDay(zone).toInstant()
        val now = Instant.now()
        val end = if (dayEnd.isAfter(now)) now else dayEnd
        val out = JSONObject()
            .put("date", date.toString())
            .put("source", "health_connect")
            .put("synced_at", ZonedDateTime.now(zone).toOffsetDateTime().toString())

        val client = clientOrNull(ctx)
        if (client == null) {
            return@withContext out.put("error", "health_connect_unavailable")
        }
        if (end.isBefore(start) || end == start) {
            return@withContext out
        }
        val range = TimeRangeFilter.between(start, end)

        // ── Dense metrics: one aggregate each, the cheapest way to ask a day ──
        agg(client, range, StepsRecord.COUNT_TOTAL)?.let { out.put("steps", it) }
        agg(client, range, ActiveCaloriesBurnedRecord.ACTIVE_CALORIES_TOTAL)?.let {
            out.put("active_kcal", it.inKilocalories.roundToInt())
        }
        agg(client, range, TotalCaloriesBurnedRecord.ENERGY_TOTAL)?.let {
            out.put("burned_kcal", it.inKilocalories.roundToInt())
        }
        if (!out.has("burned_kcal") && out.has("active_kcal")) {
            out.put("burned_kcal", out.optInt("active_kcal"))
        }
        agg(client, range, DistanceRecord.DISTANCE_TOTAL)?.let {
            val meters = it.inMeters
            out.put("distance_m", meters.roundToInt())
            out.put("distance_km", (meters / 1000.0 * 100).roundToInt() / 100.0)
        }
        agg(client, range, FloorsClimbedRecord.FLOORS_CLIMBED_TOTAL)?.let {
            out.put("floors", it.roundToInt())
        }
        agg(client, range, HydrationRecord.VOLUME_TOTAL)?.let {
            out.put("hydration_ml", it.inMilliliters.roundToInt())
        }
        agg(client, range, RestingHeartRateRecord.BPM_AVG)?.let {
            out.put("resting_hr", it.toInt())
        }
        agg(client, range, HeartRateRecord.BPM_AVG)?.let { out.put("hr_avg", it.toInt()) }
        agg(client, range, HeartRateRecord.BPM_MIN)?.let { out.put("hr_min", it.toInt()) }
        agg(client, range, HeartRateRecord.BPM_MAX)?.let { out.put("hr_max", it.toInt()) }
        agg(client, range, SpeedRecord.SPEED_AVG)?.let {
            out.put("speed_avg_kmh", (it.inMetersPerSecond * 3.6 * 10).roundToInt() / 10.0)
        }
        agg(client, range, PowerRecord.POWER_AVG)?.let {
            out.put("power_avg_w", it.inWatts.roundToInt())
        }
        agg(client, range, ElevationGainedRecord.ELEVATION_GAINED_TOTAL)?.let {
            out.put("elevation_m", it.inMeters.roundToInt())
        }

        // ── Workouts ─────────────────────────────────────────────────────────
        runCatching {
            val sessions = client.readRecords(
                ReadRecordsRequest(ExerciseSessionRecord::class, timeRangeFilter = range)
            ).records
            var minutes = 0L
            val types = linkedSetOf<String>()
            for (s in sessions) {
                minutes += java.time.Duration.between(s.startTime, s.endTime).toMinutes().coerceAtLeast(0)
                types.add(exerciseTypeLabel(s.exerciseType, s.title))
            }
            if (minutes > 0) {
                out.put("exercise_min", minutes.toInt())
                if (types.isNotEmpty()) {
                    val arr = JSONArray()
                    types.forEach { arr.put(it) }
                    out.put("exercise_types", arr)
                }
            }
        }

        // Casual movement: when no workout was logged, steps and active kcal are still
        // evidence of movement. Never invented when both are zero.
        if (!out.has("exercise_min")) {
            val steps = out.optLong("steps", 0L)
            val active = out.optInt("active_kcal", 0)
            val fromSteps = (steps / 100L).coerceAtMost(180L)
            val fromActive = if (active > 0) (active / 5.0).roundToLong().coerceAtMost(180L) else 0L
            val estimate = max(fromSteps, fromActive)
            if (estimate > 0) {
                out.put("exercise_min", estimate.toInt())
                out.put("exercise_types", JSONArray().put("walking"))
                out.put("exercise_estimated", true)
            }
        }

        // ── Sleep: the night that ENDS on this day ───────────────────────────
        runCatching {
            val nightStart = start.minusSeconds(12 * 3600L)
            val sessions = client.readRecords(
                ReadRecordsRequest(
                    SleepSessionRecord::class,
                    timeRangeFilter = TimeRangeFilter.between(nightStart, end)
                )
            ).records
            var minutes = 0L
            var deep = 0L
            var light = 0L
            var rem = 0L
            var awake = 0L
            val seen = HashSet<String>()
            for (s in sessions) {
                val key = "${s.startTime}-${s.endTime}"
                if (!seen.add(key)) continue
                minutes += java.time.Duration.between(s.startTime, s.endTime).toMinutes().coerceAtLeast(0)
                runCatching {
                    for (stage in s.stages) {
                        val len = java.time.Duration.between(stage.startTime, stage.endTime).toMinutes()
                        when (stage.stage) {
                            SleepSessionRecord.STAGE_TYPE_DEEP -> deep += len
                            SleepSessionRecord.STAGE_TYPE_LIGHT -> light += len
                            SleepSessionRecord.STAGE_TYPE_REM -> rem += len
                            SleepSessionRecord.STAGE_TYPE_AWAKE -> awake += len
                        }
                    }
                }
            }
            if (minutes > 0) {
                out.put("sleep_hours", (minutes / 60.0 * 10).roundToInt() / 10.0)
                if (deep > 0) out.put("sleep_deep_h", (deep / 60.0 * 10).roundToInt() / 10.0)
                if (light > 0) out.put("sleep_light_h", (light / 60.0 * 10).roundToInt() / 10.0)
                if (rem > 0) out.put("sleep_rem_h", (rem / 60.0 * 10).roundToInt() / 10.0)
                if (awake > 0) out.put("sleep_awake_h", (awake / 60.0 * 10).roundToInt() / 10.0)
            }
        }

        // ── Sparse metrics: a handful of records, the latest (or the average) answers ──
        runCatching {
            val records = client.readRecords(
                ReadRecordsRequest(
                    WeightRecord::class,
                    timeRangeFilter = TimeRangeFilter.between(start.minusSeconds(30L * 86400), end)
                )
            ).records
            records.maxByOrNull { it.time }?.weight?.inKilograms?.let {
                out.put("weight_kg", (it * 10).roundToInt() / 10.0)
            }
        }
        runCatching {
            client.readRecords(
                ReadRecordsRequest(HeightRecord::class, timeRangeFilter = TimeRangeFilter.between(start.minusSeconds(365L * 86400), end))
            ).records.maxByOrNull { it.time }?.height?.inMeters?.let {
                out.put("height_cm", (it * 100 * 10).roundToInt() / 10.0)
            }
        }
        runCatching {
            client.readRecords(
                ReadRecordsRequest(BodyFatRecord::class, timeRangeFilter = TimeRangeFilter.between(start.minusSeconds(30L * 86400), end))
            ).records.maxByOrNull { it.time }?.percentage?.value?.let {
                out.put("body_fat_pct", (it * 10).roundToInt() / 10.0)
            }
        }
        runCatching {
            client.readRecords(
                ReadRecordsRequest(LeanBodyMassRecord::class, timeRangeFilter = TimeRangeFilter.between(start.minusSeconds(30L * 86400), end))
            ).records.maxByOrNull { it.time }?.mass?.inKilograms?.let {
                out.put("lean_mass_kg", (it * 10).roundToInt() / 10.0)
            }
        }
        runCatching {
            client.readRecords(
                ReadRecordsRequest(BasalMetabolicRateRecord::class, timeRangeFilter = range)
            ).records.lastOrNull()?.basalMetabolicRate?.inKilocaloriesPerDay?.let {
                out.put("basal_kcal", it.roundToInt())
            }
        }
        runCatching {
            val values = client.readRecords(
                ReadRecordsRequest(HeartRateVariabilityRmssdRecord::class, timeRangeFilter = range)
            ).records.map { it.heartRateVariabilityMillis.toDouble() }
            if (values.isNotEmpty()) out.put("hrv_ms", values.average().roundToInt())
        }
        runCatching {
            val values = client.readRecords(
                ReadRecordsRequest(OxygenSaturationRecord::class, timeRangeFilter = range)
            ).records.map { it.percentage.value }
            if (values.isNotEmpty()) out.put("spo2_pct", values.average().roundToInt())
        }
        runCatching {
            val values = client.readRecords(
                ReadRecordsRequest(RespiratoryRateRecord::class, timeRangeFilter = range)
            ).records.map { it.rate }
            if (values.isNotEmpty()) out.put("respiratory_rate", (values.average() * 10).roundToInt() / 10.0)
        }
        runCatching {
            val values = client.readRecords(
                ReadRecordsRequest(BodyTemperatureRecord::class, timeRangeFilter = range)
            ).records.map { it.temperature.inCelsius }
            if (values.isNotEmpty()) out.put("body_temperature_c", (values.average() * 10).roundToInt() / 10.0)
        }
        runCatching {
            client.readRecords(
                ReadRecordsRequest(BloodPressureRecord::class, timeRangeFilter = range)
            ).records.maxByOrNull { it.time }?.let {
                out.put("bp_sys", it.systolic.inMillimetersOfMercury.roundToInt())
                out.put("bp_dia", it.diastolic.inMillimetersOfMercury.roundToInt())
            }
        }
        runCatching {
            client.readRecords(
                ReadRecordsRequest(BloodGlucoseRecord::class, timeRangeFilter = range)
            ).records.maxByOrNull { it.time }?.level?.inMilligramsPerDeciliter?.let {
                out.put("blood_glucose", it.roundToInt())
            }
        }

        out
    }

    private suspend fun <T : Any> agg(
        client: HealthConnectClient,
        range: TimeRangeFilter,
        metric: AggregateMetric<T>,
    ): T? = runCatching {
        client.aggregate(AggregateRequest(setOf(metric), timeRangeFilter = range))[metric]
    }.getOrNull()

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

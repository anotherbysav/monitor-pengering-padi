<?php
/**
 * Menyusun satu payload "snapshot" untuk dashboard: setting, reading terakhir,
 * status actuator, hasil rekomendasi, alert, dan log aktivitas.
 */

declare(strict_types=1);

namespace App;

final class DeviceState
{
    public static function snapshot(string $deviceCode, array $opts = []): array
    {
        $includeHistory = (bool) ($opts['history'] ?? false);
        $historyMinutes = (int) ($opts['history_minutes'] ?? 180);
        $limit = (int) ($opts['limit'] ?? 600);

        $reading   = Readings::latest($deviceCode);
        $controls  = Actuators::state($deviceCode);
        $profile   = Profile::active();
        $age       = $reading ? AutoControl::ageSeconds($reading) : 0;

        $trend   = Readings::trend($deviceCode, 30);
        $weather = Weather::current();

        $advisor = DryingAdvisor::evaluate($reading, $profile, $controls, [
            'age_seconds' => $age,
            'ambient_rh'  => $reading && $reading['ambient_rh_pct'] !== null
                ? (float) $reading['ambient_rh_pct']
                : $weather['humidity'],
            'ambient_temp' => $reading && $reading['ambient_temp_c'] !== null
                ? (float) $reading['ambient_temp_c']
                : $weather['temperature'],
            'weather_code' => $weather['code'],
            'trend'        => $trend,
        ]);

        $data = [
            'device' => [
                'code'        => $deviceCode,
                'name'        => Settings::str('device_name'),
                'location'    => Settings::str('location'),
                'title'       => Settings::str('dashboard_title'),
                'profile'     => $profile,
                'poll_interval_ms' => Settings::int('poll_interval_ms'),
                'offline_timeout'  => Settings::int('offline_timeout'),
            ],
            'server_time' => date('c'),
            'reading' => $reading ? [
                'temp'          => (float) $reading['temp_c'],
                'moisture'      => (float) $reading['moisture_pct'],
                'ambient_temp'  => $reading['ambient_temp_c'] === null ? null : (float) $reading['ambient_temp_c'],
                'ambient_rh'    => $reading['ambient_rh_pct'] === null ? null : (float) $reading['ambient_rh_pct'],
                'recorded_at'   => self::iso($reading['recorded_at']),
                'age_seconds'   => $age,
                'fresh'         => $age <= max(30, Settings::int('stale_reading_min') * 60),
                'source'        => $reading['source'],
            ] : null,
            'trend'     => $trend,
            'weather'   => $weather,
            'controls'  => $controls,
            'target'    => Actuators::target($deviceCode),
            'advisor'   => $advisor,
            'alerts'    => Alerts::active($deviceCode),
            'thresholds' => self::thresholds(),
        ];

        if ($includeHistory) {
            $data['history'] = Readings::history($deviceCode, $historyMinutes, $limit);
            $data['stats']   = Readings::stats($deviceCode, max(15, $historyMinutes));
        }

        return $data;
    }

    /** Ringkasan ringan untuk polling cepat (dashboard setiap 2 detik). */
    public static function pulse(string $deviceCode): array
    {
        $reading  = Readings::latest($deviceCode);
        $controls = Actuators::state($deviceCode);
        $advisor  = DryingAdvisor::evaluate($reading, Profile::active(), $controls, [
            'age_seconds' => $reading ? AutoControl::ageSeconds($reading) : 0,
            'ambient_rh'  => $reading && $reading['ambient_rh_pct'] !== null ? (float) $reading['ambient_rh_pct'] : null,
            'ambient_temp'=> $reading && $reading['ambient_temp_c'] !== null ? (float) $reading['ambient_temp_c'] : null,
            'weather_code'=> Weather::current()['code'],
            'trend'       => Readings::trend($deviceCode, 30),
        ]);

        return [
            'server_time' => date('c'),
            'reading' => $reading ? [
                'temp'        => (float) $reading['temp_c'],
                'moisture'    => (float) $reading['moisture_pct'],
                'ambient_rh'  => $reading['ambient_rh_pct'] === null ? null : (float) $reading['ambient_rh_pct'],
                'recorded_at' => self::iso($reading['recorded_at']),
                'age_seconds' => AutoControl::ageSeconds($reading),
            ] : null,
            'controls' => $controls,
            'advisor'  => $advisor,
            'alerts'   => Alerts::active($deviceCode),
            'thresholds' => self::thresholds(),
        ];
    }

    /**
     * Ambang batas pengeringan (suhu + kelembapan) dalam satu bentuk baku.
     * Dipakai snapshot, pulse, dan respons sensor/poll agar tidak divergen.
     */
    public static function thresholds(): array
    {
        return [
            'temp_min'     => Settings::float('temp_min'),
            'temp_max'     => Settings::float('temp_max'),
            'temp_optimal' => Settings::float('temp_optimal'),
            'temp_limit'   => Settings::float('temp_limit'),
            'moisture_target' => Settings::float('moisture_target'),
            'moisture_stop'   => Settings::float('moisture_stop'),
            'moisture_wet'    => Settings::float('moisture_wet'),
            'moisture_dry'    => Settings::float('moisture_dry'),
        ];
    }

    private static function iso(?string $dt): ?string
    {
        if (!$dt) {
            return null;
        }
        $ts = strtotime($dt);
        return $ts ? date('c', $ts) : null;
    }
}

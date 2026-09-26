<?php
/**
 * Sumber data cuaca (Open-Meteo, gratis tanpa API key).
 *
 * Diambil dari lokasi perangkat, di-cache 10 menit, dan tidak pernah
 * menggagalkan respons utama: bila offline, nilai cadangan digunakan.
 */

declare(strict_types=1);

namespace App;

final class Weather
{
    private static ?array $cache = null;
    private static int $cacheTime = 0;
    private const TTL = 600;

    private static function geocode(): array
    {
        $location = trim(Settings::str('location'));
        if ($location === '') {
            return ['lat' => -6.2, 'lon' => 106.816666, 'name' => 'Jakarta (default)', 'source' => 'default'];
        }

        $url = 'https://geocoding-api.open-meteo.com/v1/search?count=1&language=id&format=json&name=' . urlencode($location);
        $res = self::fetch($url, 4);
        if (isset($res['results'][0])) {
            $r = $res['results'][0];
            return [
                'lat'    => (float) $r['latitude'],
                'lon'    => (float) $r['longitude'],
                'name'   => $r['name'] . ($r['admin1'] ?? ''),
                'source' => 'open-meteo',
            ];
        }
        return ['lat' => -6.2, 'lon' => 106.816666, 'name' => $location, 'source' => 'fallback'];
    }

    public static function current(): array
    {
        if (self::$cache !== null && (time() - self::$cacheTime) < self::TTL) {
            return self::$cache;
        }

        $geo = self::geocode();
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%.4f&longitude=%.4f'
            . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m'
            . '&timezone=auto&forecast_days=1',
            $geo['lat'],
            $geo['lon']
        );

        $res = self::fetch($url, 5);
        $cur = $res['current'] ?? null;

        $weather = [
            'location'    => $geo['name'],
            'source'      => $cur ? 'open-meteo' : 'default',
            'temperature' => isset($cur['temperature_2m']) ? (float) $cur['temperature_2m'] : 29.0,
            'humidity'    => isset($cur['relative_humidity_2m']) ? (float) $cur['relative_humidity_2m'] : Settings::float('ambient_rh_default'),
            'apparent'    => isset($cur['apparent_temperature']) ? (float) $cur['apparent_temperature'] : null,
            'precipitation' => isset($cur['precipitation']) ? (float) $cur['precipitation'] : 0.0,
            'wind_speed'  => isset($cur['wind_speed_10m']) ? (float) $cur['wind_speed_10m'] : 0.0,
            'code'        => isset($cur['weather_code']) ? (int) $cur['weather_code'] : null,
            'description' => self::describe((int) ($cur['weather_code'] ?? 0)),
            'fetched_at'  => date('c'),
        ];

        if ($cur === null) {
            $weather['humidity'] = Settings::float('ambient_rh_default');
        }

        self::$cache     = $weather;
        self::$cacheTime = time();
        return $weather;
    }

    private static function fetch(string $url, int $timeout): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MonitorSuhuKelembapan/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            return null;
        }
        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : null;
    }

    public static function describe(int $code): string
    {
        return match ($code) {
            0        => 'Cerah',
            1, 2     => 'Cerah berawan',
            3        => 'Berawan',
            45, 48   => 'Berkabut',
            51, 53, 55 => 'Gerimis',
            56, 57   => 'Gerimis beku',
            61, 63, 65 => 'Hujan',
            66, 67   => 'Hujan beku',
            71, 73, 75 => 'Salju',
            80, 81, 82 => 'Hujan lokal',
            95, 96, 99 => 'Hujan badai',
            default  => 'Tidak diketahui',
        };
    }
}

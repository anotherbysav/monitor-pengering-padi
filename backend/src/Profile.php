<?php
/**
 * Profil material: parameter fisik untuk perhitungan waktu pengeringan.
 */

declare(strict_types=1);

namespace App;

final class Profile
{
    /** @var array<string,array>|null */
    private static ?array $cache = null;

    /** @return array<string,array> */
    public static function all(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }
        $rows = Db::pdo()->query('SELECT * FROM `material_profiles` ORDER BY is_default DESC, name')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['code']] = $row;
        }
        return self::$cache = $out;
    }

    public static function get(?string $code = null): array
    {
        $all = self::all();
        $code = $code ?: Settings::str('material_profile');

        if (isset($all[$code])) {
            return $all[$code];
        }
        foreach ($all as $row) {
            if ((int) $row['is_default'] === 1) {
                return $row;
            }
        }
        // fallback bila tabel kosong
        return [
            'code' => $code, 'name' => $code, 'description' => '',
            'initial_moisture' => Settings::float('moisture_initial'),
            'target_moisture'  => Settings::float('moisture_target'),
            'stop_moisture'    => Settings::float('moisture_stop'),
            'safe_temp_min'    => Settings::float('temp_min'),
            'optimal_temp'     => Settings::float('temp_optimal'),
            'safe_temp_max'    => Settings::float('temp_max'),
            'limit_temp_max'   => Settings::float('temp_limit'),
            'fan_min_duty'     => 60,
            'base_rate'        => 0.85,
            'thickness_default'=> Settings::float('layer_thickness'),
        ];
    }

    /**
     * Profil aktif, digabung dengan setting manual (batas suhu/moisture yang
     * bisa diubah user dari web selalu menang atas nilai bawaan profil).
     */
    public static function active(): array
    {
        $p = self::get();
        return [
            'code'            => $p['code'],
            'name'            => $p['name'],
            'description'     => $p['description'],
            'initial_moisture'=> (float) $p['initial_moisture'],
            'target_moisture' => Settings::float('moisture_target'),
            'stop_moisture'   => Settings::float('moisture_stop'),
            'safe_temp_min'   => Settings::float('temp_min'),
            'optimal_temp'    => Settings::float('temp_optimal'),
            'safe_temp_max'   => Settings::float('temp_max'),
            'limit_temp_max'  => Settings::float('temp_limit'),
            'fan_min_duty'    => (int) $p['fan_min_duty'],
            'base_rate'       => (float) $p['base_rate'],
            'thickness'       => Settings::float('layer_thickness') ?: (float) $p['thickness_default'],
        ];
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}

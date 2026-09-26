<?php
/**
 * Data awal (seed) untuk database.
 * Dipakai oleh backend/setup.php.
 */

return [

    /* ------------------------------------------------------------------
     * Profil material. base_rate = %/jam pada kondisi ideal (suhu optimal,
     * fan 100%, RH normal, lapisan tipis).
     * ------------------------------------------------------------------ */
    'profiles' => [
        [
            'code' => 'padi', 'name' => 'Padi (Panen Basah)',
            'description' => 'Padi hasil panen, kadar air 22-28%. Target aman 14%, stops 13,5%. Suhu aman 32-45C, titik optimal 38C.',
            'initial_moisture' => 26.0, 'target_moisture' => 14.0, 'stop_moisture' => 13.5,
            'safe_temp_min' => 32.0, 'optimal_temp' => 38.0, 'safe_temp_max' => 45.0, 'limit_temp_max' => 52.0,
            'fan_min_duty' => 60, 'base_rate' => 0.85, 'thickness_default' => 1.5, 'is_default' => 1,
        ],
        [
            'code' => 'padi_kering', 'name' => 'Padi (Setengah Kering)',
            'description' => 'Padi_symmetris dengan kadar air awal 18-20%. Pengeringan lebih cepat.',
            'initial_moisture' => 19.0, 'target_moisture' => 14.0, 'stop_moisture' => 13.5,
            'safe_temp_min' => 30.0, 'optimal_temp' => 36.0, 'safe_temp_max' => 43.0, 'limit_temp_max' => 50.0,
            'fan_min_duty' => 60, 'base_rate' => 1.25, 'thickness_default' => 1.0, 'is_default' => 0,
        ],
        [
            'code' => 'jagung', 'name' => 'Jagung SCER / Jagung Pipil',
            'description' => 'Target kadar air 14%. Fansiprop tinggi, gunakan aliran udara besar.',
            'initial_moisture' => 24.0, 'target_moisture' => 14.0, 'stop_moisture' => 13.5,
            'safe_temp_min' => 30.0, 'optimal_temp' => 36.0, 'safe_temp_max' => 42.0, 'limit_temp_max' => 48.0,
            'fan_min_duty' => 70, 'base_rate' => 0.95, 'thickness_default' => 2.0, 'is_default' => 0,
        ],
        [
            'code' => 'kelapa', 'name' => 'Kelapa Sawit / Kelapa',
            'description' => 'Kelembapan tinggi, butuh suhu lebih tinggi dan waktu lebih lama.',
            'initial_moisture' => 45.0, 'target_moisture' => 8.0, 'stop_moisture' => 7.0,
            'safe_temp_min' => 38.0, 'optimal_temp' => 45.0, 'safe_temp_max' => 55.0, 'limit_temp_max' => 62.0,
            'fan_min_duty' => 80, 'base_rate' => 0.70, 'thickness_default' => 3.0, 'is_default' => 0,
        ],
        [
            'code' => 'kayu', 'name' => 'Kayu / Papan',
            'description' => 'Target moisture content 10-12%. Pengeringan lambat, الصحية rendah.',
            'initial_moisture' => 35.0, 'target_moisture' => 11.0, 'stop_moisture' => 10.0,
            'safe_temp_min' => 30.0, 'optimal_temp' => 38.0, 'safe_temp_max' => 48.0, 'limit_temp_max' => 55.0,
            'fan_min_duty' => 60, 'base_rate' => 0.35, 'thickness_default' => 8.0, 'is_default' => 0,
        ],
        [
            'code' => 'rlai', 'name' => 'Rlapai / Tanaman Umbi',
            'description' => 'Kenyang air, target 12%. Ventilasi besar diperlukan.',
            'initial_moisture' => 75.0, 'target_moisture' => 12.0, 'stop_moisture' => 11.0,
            'safe_temp_min' => 28.0, 'optimal_temp' => 35.0, 'safe_temp_max' => 42.0, 'limit_temp_max' => 48.0,
            'fan_min_duty' => 70, 'base_rate' => 0.80, 'thickness_default' => 5.0, 'is_default' => 0,
        ],
    ],

    /* ------------------------------------------------------------------
     * Setting aplikasi. nilai = nilai bawaan, bisa diubah lewat web.
     * ------------------------------------------------------------------ */
    'settings' => [

        // ===== Sistem =====
        ['setting_key' => 'device_code',        'setting_value' => 'DRYER-01',            'value_type' => 'string', 'group_name' => 'sistem',    'label' => 'Kode Perangkat',        'unit' => ''],
        ['setting_key' => 'device_name',        'setting_value' => 'Pengering Padi Utama', 'value_type' => 'string', 'group_name' => 'sistem',  'label' => 'Nama Perangkat',        'unit' => ''],
        ['setting_key' => 'location',           'setting_value' => 'Rumah Pengering',     'value_type' => 'string', 'group_name' => 'sistem',   'label' => 'Lokasi',                 'unit' => ''],
        ['setting_key' => 'material_profile',   'setting_value' => 'padi',                'value_type' => 'string', 'group_name' => 'sistem',   'label' => 'Profil Material',        'unit' => ''],
        ['setting_key' => 'dashboard_title',    'setting_value' => 'Monitor Suhu & Kelembapan Pengering Padi', 'value_type' => 'string', 'group_name' => 'sistem', 'label' => 'Judul Dashboard', 'unit' => ''],

        // ===== Batas suhu (bisa diubah via web) =====
        ['setting_key' => 'temp_min',           'setting_value' => '32',  'value_type' => 'float', 'group_name' => 'suhu', 'label' => 'Batas Bawah Suhu', 'unit' => 'C', 'min_value' => 5,  'max_value' => 90],
        ['setting_key' => 'temp_max',           'setting_value' => '45',  'value_type' => 'float', 'group_name' => 'suhu', 'label' => 'Batas Atas Suhu',   'unit' => 'C', 'min_value' => 10, 'max_value' => 95],
        ['setting_key' => 'temp_optimal',       'setting_value' => '38',  'value_type' => 'float', 'group_name' => 'suhu', 'label' => 'Suhu Optimal',        'unit' => 'C', 'min_value' => 10, 'max_value' => 90],
        ['setting_key' => 'temp_limit',         'setting_value' => '52',  'value_type' => 'float', 'group_name' => 'suhu', 'label' => 'Batas Keras (Auto Matikan)', 'unit' => 'C', 'min_value' => 20, 'max_value' => 120],
        ['setting_key' => 'temp_hysteresis',    'setting_value' => '0.8', 'value_type' => 'float', 'group_name' => 'suhu', 'label' => 'Histeresis Kontrol',   'unit' => 'C', 'min_value' => 0.1, 'max_value' => 5],

        // ===== Batas kelembapan =====
        ['setting_key' => 'moisture_target',    'setting_value' => '14',    'value_type' => 'float', 'group_name' => 'kelembapan', 'label' => 'Kelembapan Target',  'unit' => '%',  'min_value' => 1,  'max_value' => 100],
        ['setting_key' => 'moisture_stop',      'setting_value' => '13.5',  'value_type' => 'float', 'group_name' => 'kelembapan', 'label' => 'Kelembapan Berhenti', 'unit' => '%', 'min_value' => 1,  'max_value' => 100],
        ['setting_key' => 'moisture_initial',   'setting_value' => '26',    'value_type' => 'float', 'group_name' => 'kelembapan', 'label' => 'Kelembapan Awal',    'unit' => '%',  'min_value' => 1,  'max_value' => 100],
        ['setting_key' => 'moisture_wet',       'setting_value' => '20',    'value_type' => 'float', 'group_name' => 'kelembapan', 'label' => 'Batas Basah (Butuh Aktif)', 'unit' => '%', 'min_value' => 1, 'max_value' => 100],
        ['setting_key' => 'moisture_dry',       'setting_value' => '12',    'value_type' => 'float', 'group_name' => 'kelembapan', 'label' => 'Batas Kering',       'unit' => '%',  'min_value' => 0,  'max_value' => 100],
        ['setting_key' => 'ambient_rh_default', 'setting_value' => '65',    'value_type' => 'float', 'group_name' => 'kelembapan', 'label' => 'RH Ambient Cadangan', 'unit' => '%', 'min_value' => 5,  'max_value' => 100],

        // ===== Kontrol actuator =====
        ['setting_key' => 'auto_mode',          'setting_value' => '1',  'value_type' => 'bool', 'group_name' => 'kontrol', 'label' => 'Mode Otomatis',       'unit' => ''],
        ['setting_key' => 'heater_manual',      'setting_value' => '0',  'value_type' => 'bool', 'group_name' => 'kontrol', 'label' => 'Heater Mode Manual',   'unit' => ''],
        ['setting_key' => 'heater_duty',        'setting_value' => '80', 'value_type' => 'int',  'group_name' => 'kontrol', 'label' => 'Duty Heater',         'unit' => '%', 'min_value' => 0, 'max_value' => 100],
        ['setting_key' => 'fan_manual',         'setting_value' => '0',  'value_type' => 'bool', 'group_name' => 'kontrol', 'label' => 'Fan Mode Manual',      'unit' => ''],
        ['setting_key' => 'fan_duty',           'setting_value' => '80', 'value_type' => 'int',  'group_name' => 'kontrol', 'label' => 'Duty Fan',            'unit' => '%', 'min_value' => 0, 'max_value' => 100],

        // ===== Keamanan =====
        ['setting_key' => 'safety_max_runtime', 'setting_value' => '180',  'value_type' => 'int', 'group_name' => 'keamanan', 'label' => 'Maks. Lama Heater Nyala', 'unit' => 'menit', 'min_value' => 1, 'max_value' => 1440],
        ['setting_key' => 'safety_cooldown',    'setting_value' => '10',   'value_type' => 'int', 'group_name' => 'keamanan', 'label' => 'Jeda Anti Hubung Short', 'unit' => 'menit', 'min_value' => 0, 'max_value' => 120],
        ['setting_key' => 'safety_auto_off',    'setting_value' => '1',    'value_type' => 'bool','group_name' => 'keamanan', 'label' => 'Auto Matikan saat Bahaya','unit' => ''],
        ['setting_key' => 'safety_heater_rate', 'setting_value' => '1.2',  'value_type' => 'float','group_name' => 'keamanan', 'label' => 'Alarm Naik Cepat',    'unit' => 'C/menit', 'min_value' => 0.1, 'max_value' => 10],
        ['setting_key' => 'safety_fault_temp',  'setting_value' => '90',   'value_type' => 'float','group_name' => 'keamanan', 'label' => 'Suhu Gangguan (Sensor Rusak)', 'unit' => 'C', 'min_value' => 50, 'max_value' => 200],

        // ===== Sistem / operasional =====
        ['setting_key' => 'poll_interval_ms',   'setting_value' => '3000', 'value_type' => 'int',   'group_name' => 'sistem', 'label' => 'Interval Kirim Sensor', 'unit' => 'ms', 'min_value' => 500, 'max_value' => 60000],
        ['setting_key' => 'offline_timeout',    'setting_value' => '20',   'value_type' => 'int',   'group_name' => 'sistem', 'label' => 'Batas Data Offline',    'unit' => 'detik', 'min_value' => 5, 'max_value' => 600],
        ['setting_key' => 'stale_reading_min',  'setting_value' => '10',   'value_type' => 'int',   'group_name' => 'sistem', 'label' => 'Batas Data Lama',      'unit' => 'menit', 'min_value' => 1, 'max_value' => 120],
        ['setting_key' => 'retention_days',     'setting_value' => '30',   'value_type' => 'int',   'group_name' => 'sistem', 'label' => 'Simpan History',       'unit' => 'hari', 'min_value' => 1, 'max_value' => 3650],
        ['setting_key' => 'layer_thickness',    'setting_value' => '1.5',  'value_type' => 'float', 'group_name' => 'sistem', 'label' => 'Ketebalan Lapisan',    'unit' => 'mm', 'min_value' => 0.2, 'max_value' => 50],
        ['setting_key' => 'log_interval',       'setting_value' => '1',    'value_type' => 'bool',  'group_name' => 'sistem', 'label' => 'Catat Rekomendasi',    'unit' => ''],
    ],

    /* ------------------------------------------------------------------
     * Akun dashboard (login web)
     * ------------------------------------------------------------------ */
    'admin' => [
        'username' => 'admin',
        // plaintext, di-hash saat di-seed
        'password' => 'admin123',
        'name' => 'Administrator',
    ],
];

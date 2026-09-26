# Monitor Suhu & Kelembapan Pengering Padi

Dashboard web untuk memantau dan mengontrol pengering padi: suhu, kelembapan, rekomendasi
pengeringan, serta kontrol heater dan kipas.

- **Frontend:** Vue 3 + Vite
- **Backend:** PHP (tanpa framework)
- **Database:** MySQL / MariaDB
- **Integrasi perangkat:** REST API + Modbus TCP

---

## 1. Kebutuhan Sistem

| Komponen | Versi |
|---|---|
| PHP | 8.1+ (disarankan 8.3) dengan ekstensi `pdo_mysql` |
| MySQL / MariaDB | 5.7+ / 10.4+ |
| Node.js | 20+ (hanya untuk membangun frontend) |

---

## 2. Instalasi Lokal

```bash
# 1. Pasang dependensi + bangun frontend
npm install
npm run build

# 2. Salin konfigurasi lokal dan sesuaikan
cp backend/config.example.php backend/config.local.php
# Windows PowerShell:
#   copy backend\config.example.php backend\config.local.php

# 3. Siapkan database + data awal
php backend/setup.php --demo

# 4. Jalankan
npm run serve
```

Buka `http://127.0.0.1:8000`

> **Catatan:** `npm run serve` memakai `php -S` (server bawaan PHP) sehingga
> aturan rewrite tidak diperlukan. Untuk **Apache**, file `public/.htaccess`
> sudah disertakan.

---

## 3. Struktur Proyek

```
├── backend/
│   ├── config.php            # konfigurasi dasar (di-commit)
│   ├── config.example.php    # TEMPLAT untuk server (di-commit)
│   ├── config.local.php      # KREDENSIAL ASLI (JANGAN di-commit)
│   ├── setup.php             # installer database
│   ├── database/schema.sql   # struktur tabel
│   ├── device/               # simulator + server Modbus
│   └── src/                  # kelas: Auth, DeviceState, Actuators, dll
├── frontend/                 # sumber Vue (Vite)
├── public/                   # DOCUMENT ROOT server web
│   ├── index.php             # front controller + router API
│   ├── .htaccess             # rewrite Apache
│   ├── index.html            # hasil build Vite
│   └── assets/               # hasil build Vite
└── package.json
```

---

## 4. Konfigurasi

Semua konfigurasi runtime ada di `backend/config.local.php` (diabaikan Git):

```php
return [
    'app' => [
        'debug'   => false,   // WAJIB false di produksi
        'timezone' => 'Asia/Jakarta',
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'nama_database',
        'user' => 'user_database',
        'pass' => 'password_database',
    ],
];
```

Nilai di `config.local.php` **menimpa** `config.php`, jadi Anda cukup mengisi
bagian yang berubah.

---

## 5. Endpoint API

### Dashboard (publik, tanpa autentikasi)

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/health` | Cek koneksi server |
| GET | `/api/state` | Snapshot lengkap untuk dashboard |
| GET | `/api/pulse` | Data ringan untuk polling (2,5 detik) |
| GET | `/api/readings/history` | Riwayat sensor |
| GET | `/api/readings/stats` | Statistik periode |
| GET | `/api/settings` | Ambil semua pengaturan |
| PUT | `/api/settings` | Simpan pengaturan |
| POST | `/api/controls` | Kendalikan heater / kipas |
| POST | `/api/controls/all-off` | Matikan semua aktuator |
| GET | `/api/alerts` | Daftar peringatan |
| GET | `/api/recommendation` | Rekomendasi pengeringan |
| GET | `/api/devices` | Perangkat & daftar API key |

### Perangkat IoT (wajib `X-Api-Key`)

| Method | Endpoint | Fungsi |
|---|---|---|
| POST | `/api/sensor/ingest` | Kirim data sensor + status aktuator |
| GET | `/api/sensor/poll` | Ambil perintah aktuator terbaru |

---

## 6. Kirim Data dari ESP32 / Arduino

```cpp
#include <WiFi.h>
#include <HTTPClient.h>

const char* API_KEY = "API_KEY_ANDA";

void kirimData(float suhu, float lembap, bool heaterOn, bool fanOn) {
  HTTPClient http;
  http.begin("http://IP-SERVER/api/sensor/ingest");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", API_KEY);

  String body = "{\"temp_c\":" + String(suhu)
              + ",\"moisture_pct\":" + String(lembap)
              + ",\"heater_on\":"  + (heaterOn ? "true" : "false")
              + ",\"fan_on\":"      + (fanOn   ? "true" : "false")
              + ",\"device_code\":\"DRYER-01\"}";

  int kode = http.POST(body);
  http.end();
}
```

Ambil perintah aktuator (untuk dibaca mikrokontroler, agar sinkron dengan dashboard):

```bash
curl "http://IP-SERVER/api/sensor/poll" -H "X-Api-Key: API_KEY_ANDA"
```

Buat API key baru lewat dashboard: **Perangkat & API → API Key Perangkat → Buat API Key**.
Nilai penuh hanya ditampilkan **satu kali** — simpan langsung.

---

## 7. Menjalankan Mode Auto

Dashboard punya dua mode kontrol:

- **Otomatis** — backend memutuskan nyala/mati berdasarkan pengaturan
- **Manual** — operator clicking saklar secara langsung

Mengeklik saklar heater atau kipas pada dashboard **otomatis beralih ke mode
manual**, lalu perintah Anda berlaku.

---

## 8. Simulator (untuk mencoba)

```bash
npm run simulate    # kirim data sensor palsu
npm run modbus      # server Modbus TCP
npm run seed        # isi data contoh
```

---

## 9. Deploy

### VPS (Ubuntu + Nginx)

```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-mysql mariadb-server
git clone https://github.com/anotherbysav/monitor-pengering-padi.git /var/www/pengering
cd /var/www/pengering
cp backend/config.example.php backend/config.local.php
nano backend/config.local.php          # isi kredensial DB, set debug=false
php backend/setup.php --demo
sudo chown -R www-data /var/www/pengering
```

Virtual host Nginx (`/etc/nginx/sites-available/pengering`):

```nginx
server {
    listen 80;
    server_name domain-anda.com www.domain-anda.com;
    root /var/www/pengering/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # lindungi folder di luar document root
    location ~ ^/backend/ { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/pengering /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d domain-anda.com     # SSL gratis
```

### Shared Hosting (cPanel)

1. **cPanel → Git™ Versioning** → paste URL repo + Personal Access Token → **Clone**
2. **MySQL Databases** → buat database + user, catat password
3. **Terminal** cPanel:
   ```bash
   cp backend/config.example.php backend/config.local.php
   nano backend/config.local.php
   php backend/setup.php --demo
   ```
4. **Domains →** arahkan document root ke folder `public/`
5. SSL gratis dari **Let's Encrypt** di cPanel

### Render / Railway (subdomain gratis)

Repo ini bisa di-deploy langsung dari GitHub, **jika** Anda menambahkan
`Dockerfile` (PHP tidak tersedia secara native di platform tersebut).
Lihat `render.yaml` bila sudah tersedia.

> **GitHub Pages tidak bisa dipakai** untuk proyek ini: Pages hanya mendukung
> situs statis, sedangkan aplikasi ini memerlukan PHP + MySQL.

---

## 10. Keamanan

- `backend/config.local.php` **wajib** diabaikan Git (sudah ada di `.gitignore`)
- `debug` **wajib** `false` di produksi
- API key perangkat hanya ditampilkan sekali saat dibuat
- Ganti API key bila pernah bocor: **Perangkat & API → Hapus → Buat baru**

---

## Lisensi

MIT

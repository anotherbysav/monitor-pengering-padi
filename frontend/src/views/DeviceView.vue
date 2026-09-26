<script setup>
/**
 * Halaman perangkat & API:
 * - daftar perangkat dan status koneksi
 * - pembuatan / penghapusan API key
 * - contoh kode integrasi (curl, Arduino, Python, Modbus)
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import { api, notify, notifyError, loadState } from '@/stores'
import { dateTime, ago, num } from '@/utils/format'

const loading = ref(true)
const devices = ref([])
const keys = ref([])
const newKey = ref(null)
const label = ref('')
const busy = ref(false)
const copying = ref('')
const activeTab = ref('keys')

const CODES = {
  curl: `curl -X POST http://IP-SERVER/api/sensor/ingest \\
  -H "X-Api-Key: API_KEY_ANDA" \\
  -H "Content-Type: application/json" \\
  -d '{"temp_c": 38.5, "moisture_pct": 22.4, "ambient_rh_pct": 65,
       "fan_on": true, "fan_duty": 85,
       "heater_on": true, "heater_duty": 60}'`,

  poll: `# Perangkat mengambil status aktuator & perintah
curl "http://IP-SERVER/api/sensor/poll?api_key=API_KEY_ANDA"

# Response (data.command):
# {
#   "heater": { "on": true,  "duty": 60 },
#   "fan":    { "on": true,  "duty": 85 }
# }`,

  arduino: `#include <WiFi.h>
#include <HTTPClient.h>

const char* HOST  = "192.168.1.10";   // alamat server dashboard
const char* KEY   = "msk_xxxxxxxxxxxx";
const int   PIN_TEMP = 4;             // thermocouple (MAX6675)
const int   PIN_MOIST = 34;           // sensor moisture analog

WiFi.begin("SSID", "PASSWORD");

void setup() {
  Serial.begin(115200);
  WiFi.mode(WIFI_STA);
  while (WiFi.status() != WL_CONNECTED) delay(500);
  pinMode(PIN_TEMP, INPUT);
  pinMode(PIN_MOIST, INPUT);
}

void loop() {
  float tempC = readThermocouple();          // sensor MAX6675 / thermocouple
  float moist = analogRead(PIN_MOIST);
  moist = map(moist, 0, 4095, 100, 0);      // kalibrasi sesuai sensor Anda

  HTTPClient http;
  String url = String("http://") + HOST + "/api/sensor/ingest";
  http.begin(url);
  http.addHeader("X-Api-Key", KEY);
  http.addHeader("Content-Type", "application/json");

  String body = "{\\"temp_c\\":" + String(tempC, 1)
              + ",\\"moisture_pct\\":" + String(moist, 1)
              + ",\\"ambient_rh_pct\\":65}";

  if (http.POST(body) != 200) {
    Serial.println("Gagal kirim: " + http.errorToString(http.getResponseCode()));
  } else {
    Serial.println(http.getString());
  }
  http.end();
  delay(5000);   // sesuaikan dengan poll_interval_ms
}`,

  python: `import requests, time

API  = "http://192.168.1.10/api/sensor/ingest"
KEY  = "msk_xxxxxxxxxxxx"
HEAD = {"X-Api-Key": KEY}

def kirim(temp_c, moisture_pct, rh=65):
    r = requests.post(API, headers=HEAD, json={
        "temp_c": temp_c,
        "moisture_pct": moisture_pct,
        "ambient_rh_pct": rh,
        "fan_on": True,
        "fan_duty": 85,
    }, timeout=10)
    r.raise_for_status()
    return r.json()["data"]["advisor"]

while True:
    adv = kirim(38.5, 22.4)
    print(adv["label"], "->", adv["eta"]["text"])
    time.sleep(5)`,

  modbus: `// ---- Register map (Modbus TCP, port 5020) ----
// 40001 SUHU_BCD        RW _sensor thermocouple, x10
// 40003 KELEMBAPAN_BCD  RW  sensor moisture, x10
// 40005 RH_AMBIEN_BCD   RW  RH_percent, x10
// 40007 HEATER_ACTUAL   RW  0/1
// 40009 HEATER_DUTY     RW  0..100
// 40011 FAN_ACTUAL      RW  0/1
// 40013 FAN_DUTY        RW  0..100
// 40015 HEATER_CMD      R_  perintah dari dashboard
// 40017 FAN_CMD         R_  perintah dari dashboard
// 40019 STATUS          R_  bit0 online, bit1 auto mode

// Arduino/ESP32 (client Modbus TCP):
#include <ModbusTCP.h>
ModbusClient client(192.168.1.10, 5020);

void loop() {
  client.begin();
  client.writeRegisters(40001, 2, (uint16_t[]){ (uint16_t)(38.5 * 10),
                                               (uint16_t)(22.4 * 10) });
  int duty = client.readRegisters(40017, 1)[0];   // FAN_CMD
  digitalWrite(PIN_RELAY, duty > 0 ? HIGH : LOW);
  delay(2000);
}`
}

async function load() {
  loading.value = true
  try {
    const r = await api.devices()
    devices.value = r.data.devices
    keys.value = r.data.api_keys
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

async function createKey() {
  const code = devices.value[0]?.device_code || 'DRYER-01'
  busy.value = true
  try {
    const r = await api.createApiKey(label.value.trim() || 'Perangkat IoT', code)
    newKey.value = r.data.api_key
    label.value = ''
    await load()
    notify('API key dibuat. Simpan sekarang — tidak akan ditampilkan lagi.', 'success', 7000)
  } catch (e) {
    notifyError(e)
  } finally {
    busy.value = false
  }
}

async function removeKey(k) {
  if (!window.confirm(`Hapus API key "${k.label}"? Perangkat yang memakainya akan gagal mengirim data.`)) return
  try {
    await api.deleteApiKey(k.id)
    await load()
    notify('API key dihapus.', 'success')
  } catch (e) {
    notifyError(e)
  }
}

async function copy(text, id) {
  try {
    await navigator.clipboard.writeText(text)
    copying.value = id
    setTimeout(() => (copying.value = ''), 1600)
    notify('Kode disalin ke clipboard.', 'success', 2000)
  } catch {
    notify('Browser menolak akses clipboard. Salin manual.', 'warning')
  }
}

let timer = null
onMounted(() => {
  load()
  timer = setInterval(load, 30000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="dev">
    <!-- perangkat -->
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><AppIcon name="server" :size="16" /> Perangkat Terdaftar</div>
          <div class="card__subtitle">Status koneksi dan informasi perangkat pengering</div>
        </div>
        <button class="btn btn--sm" :disabled="loading" @click="load">
          <AppIcon name="refresh" :size="13" :spin="loading" /> Muat ulang
        </button>
      </div>

      <div class="grid grid--2">
        <div v-for="d in devices" :key="d.device_code" class="dev__device">
          <div class="dev__device-head">
            <span class="dev__device-icon"><AppIcon name="chip" :size="18" /></span>
            <div style="flex: 1; min-width: 0">
              <div style="font-weight: 660; font-size: 14px">{{ d.name || d.device_code }}</div>
              <div class="mono faint" style="font-size: 11.5px">{{ d.device_code }}</div>
            </div>
            <span class="badge" :class="d.online ? 'badge--live' : 'badge--danger'">
              <span class="dot" :class="d.online ? 'dot--pulse' : ''" />
              {{ d.online ? 'Online' : 'Offline' }}
            </span>
          </div>
          <ul class="dev__meta">
            <li><span>Lokasi</span><strong>{{ d.location || '—' }}</strong></li>
            <li><span>Profil</span><strong>{{ d.profile_code || '—' }}</strong></li>
            <li><span>Terakhir terlihat</span><strong class="mono">{{ d.last_seen ? ago(d.last_seen_ago) : 'belum pernah' }}</strong></li>
            <li><span>IP terakhir</span><strong class="mono">{{ d.last_ip || '—' }}</strong></li>
            <li><span>Firmware</span><strong class="mono">{{ d.firmware || '—' }}</strong></li>
          </ul>
        </div>
        <p v-if="!devices.length && !loading" class="faint">Belum ada perangkat terdaftar.</p>
      </div>
    </div>

    <!-- API key -->
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><AppIcon name="key" :size="16" /> API Key Perangkat</div>
          <div class="card__subtitle">
            Digunakan sebagai header <span class="mono">X-Api-Key</span> untuk kirim data sensor
          </div>
        </div>
        <div class="dev__tabs">
          <button class="dev__tab" :class="{ 'is-on': activeTab === 'keys' }" @click="activeTab = 'keys'">Kunci</button>
          <button class="dev__tab" :class="{ 'is-on': activeTab === 'code' }" @click="activeTab = 'code'">Kode</button>
        </div>
      </div>

      <template v-if="activeTab === 'keys'">
        <!-- key baru -->
        <Transition name="fade">
          <div v-if="newKey" class="dev__newkey">
            <AppIcon name="alert" :size="16" style="color: var(--amber); flex: none; margin-top: 2px" />
            <div style="flex: 1; min-width: 0">
              <strong style="font-size: 13px">Simpan API key ini sekarang</strong>
              <p class="hint" style="margin-top: 2px">
                Demi keamanan, nilai lengkapnya hanya ditampilkan sekali.
              </p>
              <div class="dev__keybox">
                <code class="mono">{{ newKey }}</code>
                <button class="btn btn--sm" @click="copy(newKey, 'new')">
                  <AppIcon name="copy" :size="13" /> {{ copying === 'new' ? 'Tersalin' : 'Salin' }}
                </button>
              </div>
            </div>
            <button class="btn btn--icon" @click="newKey = null"><AppIcon name="x" :size="14" /></button>
          </div>
        </Transition>

        <div class="row gap-8" style="margin-bottom: 16px; flex-wrap: wrap">
          <input v-model="label" class="input" style="max-width: 260px" placeholder="Label perangkat (mis. ESP32 Gudang)" />
          <button class="btn btn--primary" :disabled="busy" @click="createKey">
            <AppIcon name="plus" :size="15" /> {{ busy ? 'Membuat…' : 'Buat API Key' }}
          </button>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Label</th>
                <th>Perangkat</th>
                <th>Cakupan</th>
                <th>Dibuat</th>
                <th>Terakhir dipakai</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="k in keys" :key="k.id">
                <td>
                  <span class="row gap-6">
                    <AppIcon name="key" :size="13" />
                    <strong>{{ k.label }}</strong>
                  </span>
                </td>
                <td class="mono">{{ k.device_code }}</td>
                <td>
                  <span v-for="s in String(k.scopes || '').split(',').filter(Boolean)" :key="s" class="badge" style="margin-right: 4px">
                    {{ s.trim() }}
                  </span>
                </td>
                <td class="faint mono">{{ dateTime(k.created_at) }}</td>
                <td class="faint mono">{{ k.last_used_at ? ago(k.last_used_ago) : 'belum dipakai' }}</td>
                <td class="text-right">
                  <button class="btn btn--icon" title="Hapus" @click="removeKey(k)">
                    <AppIcon name="trash" :size="14" />
                  </button>
                </td>
              </tr>
              <tr v-if="!keys.length">
                <td colspan="6" class="text-center faint" style="padding: 24px">Belum ada API key.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else>
        <div class="dev__codes">
          <div v-for="(code, id) in CODES" :key="id" class="dev__code">
            <div class="dev__code-head">
              <span class="section-title">{{
                { curl: 'Kirim data (cURL)', poll: 'Ambil status aktuator', arduino: 'Arduino / ESP32', python: 'Python', modbus: 'Modbus TCP' }[id]
              }}</span>
              <button class="btn btn--sm" @click="copy(code, id)">
                <AppIcon name="copy" :size="13" /> {{ copying === id ? 'Tersalin' : 'Salin' }}
              </button>
            </div>
            <pre class="dev__pre"><code>{{ code }}</code></pre>
          </div>
        </div>
      </template>
    </div>

    <!-- endpoint -->
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><AppIcon name="info" :size="16" /> Ringkasan Endpoint</div>
          <div class="card__subtitle">Kontrak integrasi untuk perangkat IoT / gateway Modbus</div>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Endpoint</th>
              <th>Metode</th>
              <th>Autentikasi</th>
              <th>Kegunaan</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="mono">/api/sensor/ingest</td>
              <td><span class="badge badge--info">POST</span></td>
              <td><span class="badge badge--busy">X-Api-Key</span></td>
              <td class="muted">Kirim data sensor + status aktuator</td>
            </tr>
            <tr>
              <td class="mono">/api/sensor/poll</td>
              <td><span class="badge badge--info">GET</span></td>
              <td><span class="badge badge--busy">?api_key=</span></td>
              <td class="muted">Ambil perintah heater/fan dari dashboard</td>
            </tr>
            <tr>
              <td class="mono">/api/health</td>
              <td><span class="badge badge--live">GET</span></td>
              <td><span class="badge">publik</span></td>
              <td class="muted">Cek koneksi server</td>
            </tr>
            <tr>
              <td class="mono">/api/state</td>
              <td><span class="badge badge--live">GET</span></td>
              <td><span class="badge">session</span></td>
              <td class="muted">Snapshot dashboard lengkap</td>
            </tr>
            <tr>
              <td class="mono">/api/controls</td>
              <td><span class="badge badge--warn">POST</span></td>
              <td><span class="badge">session</span></td>
              <td class="muted">Kontrol heater/fan dari web</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.dev {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.dev__device {
  padding: 15px;
  border-radius: var(--radius-sm);
  background: var(--sunken);
  border: 1px solid var(--border);
  transition: border-color 0.3s var(--ease), transform 0.3s var(--ease-out);
}

.dev__device:hover {
  border-color: var(--border-strong);
  transform: translateY(-2px);
}

.dev__device-head {
  display: flex;
  align-items: center;
  gap: 11px;
  margin-bottom: 13px;
}

.dev__device-icon {
  width: 38px;
  height: 38px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  background: rgba(3, 105, 161, 0.13);
  border: 1px solid rgba(3, 105, 161, 0.26);
  color: var(--sky);
  flex: none;
}

.dev__meta {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.dev__meta li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 12px;
  color: var(--text-faint);
}

.dev__meta strong {
  color: var(--text);
  font-weight: 600;
  font-size: 12px;
}

.dev__tabs {
  display: flex;
  gap: 3px;
  background: var(--sunken);
  padding: 3px;
  border-radius: 9px;
  border: 1px solid var(--border);
}

.dev__tab {
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  color: var(--text-faint);
  transition: all 0.25s var(--ease);
}

.dev__tab.is-on {
  background: rgba(3, 105, 161, 0.18);
  color: var(--sky);
}

.dev__newkey {
  display: flex;
  align-items: flex-start;
  gap: 11px;
  padding: 14px;
  border-radius: var(--radius-sm);
  background: rgba(180, 83, 9, 0.1);
  border: 1px solid rgba(180, 83, 9, 0.35);
  margin-bottom: 16px;
}

.dev__keybox {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 9px;
  padding: 9px 11px;
  border-radius: 8px;
  background: var(--overlay);
  border: 1px dashed rgba(180, 83, 9, 0.4);
  flex-wrap: wrap;
}

.dev__keybox code {
  flex: 1;
  min-width: 200px;
  font-size: 13px;
  color: var(--amber);
  word-break: break-all;
}

.dev__codes {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

@media (max-width: 980px) {
  .dev__codes {
    grid-template-columns: minmax(0, 1fr);
  }
}

.dev__code {
  border-radius: var(--radius-sm);
  background: var(--sunken);
  border: 1px solid var(--border);
  overflow: hidden;
}

.dev__code-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
}

.dev__pre {
  margin: 0;
  padding: 12px 14px;
  font-family: var(--mono);
  font-size: 11.5px;
  line-height: 1.65;
  color: var(--text-dim);
  overflow-x: auto;
  max-height: 340px;
  white-space: pre;
}

.gap-6 {
  gap: 6px;
}
</style>

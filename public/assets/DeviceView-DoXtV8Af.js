import{_ as N,p as H,P as W,c as n,b as a,h as i,f as r,A as d,F as m,r as k,d as P,i as T,w as z,B,W as O,X as F,t as s,a as U,q as S,H as w,l as c,G as x,o as l,u as C,k as R,m as L}from"./index-sSDIddgj.js";const V={class:"dev"},Y={class:"card"},X={class:"card__head"},G={class:"card__title"},$=["disabled"],j={class:"grid grid--2"},q={class:"dev__device-head"},J={class:"dev__device-icon"},Q={style:{flex:"1","min-width":"0"}},Z={style:{"font-weight":"660","font-size":"14px"}},aa={class:"mono faint",style:{"font-size":"11.5px"}},ta={class:"dev__meta"},ea={class:"mono"},sa={class:"mono"},na={class:"mono"},ia={key:0,class:"faint"},la={class:"card"},oa={class:"card__head"},da={class:"card__title"},ra={class:"dev__tabs"},ua={key:0,class:"dev__newkey"},ca={style:{flex:"1","min-width":"0"}},pa={class:"dev__keybox"},_a={class:"mono"},va={class:"row gap-8",style:{"margin-bottom":"16px","flex-wrap":"wrap"}},ba=["disabled"],ma={class:"table-wrap"},ga={class:"table"},fa={class:"row gap-6"},ha={class:"mono"},ya={class:"faint mono"},ka={class:"faint mono"},Ta={class:"text-right"},xa=["onClick"],Aa={key:0},Pa={key:1,class:"dev__codes"},Sa={class:"dev__code-head"},wa={class:"section-title"},Ca=["onClick"],Ea={class:"dev__pre"},Ia={class:"card"},Ra={class:"card__head"},Ka={class:"card__title"},Ma={__name:"DeviceView",setup(Da){const p=c(!0),g=c([]),A=c([]),_=c(null),f=c(""),h=c(!1),y=c(""),v=c("keys"),K={curl:`curl -X POST http://IP-SERVER/api/sensor/ingest \\
  -H "X-Api-Key: API_KEY_ANDA" \\
  -H "Content-Type: application/json" \\
  -d '{"temp_c": 38.5, "moisture_pct": 22.4, "ambient_rh_pct": 65,
       "fan_on": true, "fan_duty": 85,
       "heater_on": true, "heater_duty": 60}'`,poll:`# Perangkat mengambil status aktuator & perintah
curl "http://IP-SERVER/api/sensor/poll?api_key=API_KEY_ANDA"

# Response (data.command):
# {
#   "heater": { "on": true,  "duty": 60 },
#   "fan":    { "on": true,  "duty": 85 }
# }`,arduino:`#include <WiFi.h>
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
}`,python:`import requests, time

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
    time.sleep(5)`,modbus:`// ---- Register map (Modbus TCP, port 5020) ----
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
}`};async function b(){p.value=!0;try{const o=await S.devices();g.value=o.data.devices,A.value=o.data.api_keys}catch(o){w(o)}finally{p.value=!1}}async function M(){var t;const o=((t=g.value[0])==null?void 0:t.device_code)||"DRYER-01";h.value=!0;try{const e=await S.createApiKey(f.value.trim()||"Perangkat IoT",o);_.value=e.data.api_key,f.value="",await b(),x("API key dibuat. Simpan sekarang — tidak akan ditampilkan lagi.","success",7e3)}catch(e){w(e)}finally{h.value=!1}}async function D(o){if(window.confirm(`Hapus API key "${o.label}"? Perangkat yang memakainya akan gagal mengirim data.`))try{await S.deleteApiKey(o.id),await b(),x("API key dihapus.","success")}catch(t){w(t)}}async function E(o,t){try{await navigator.clipboard.writeText(o),y.value=t,setTimeout(()=>y.value="",1600),x("Kode disalin ke clipboard.","success",2e3)}catch{x("Browser menolak akses clipboard. Salin manual.","warning")}}let I=null;return H(()=>{b(),I=setInterval(b,3e4)}),W(()=>clearInterval(I)),(o,t)=>(l(),n("div",V,[a("div",Y,[a("div",X,[a("div",null,[a("div",G,[i(d,{name:"server",size:16}),t[5]||(t[5]=r(" Perangkat Terdaftar",-1))]),t[6]||(t[6]=a("div",{class:"card__subtitle"},"Status koneksi dan informasi perangkat pengering",-1))]),a("button",{class:"btn btn--sm",disabled:p.value,onClick:b},[i(d,{name:"refresh",size:13,spin:p.value},null,8,["spin"]),t[7]||(t[7]=r(" Muat ulang ",-1))],8,$)]),a("div",j,[(l(!0),n(m,null,k(g.value,e=>(l(),n("div",{key:e.device_code,class:"dev__device"},[a("div",q,[a("span",J,[i(d,{name:"chip",size:18})]),a("div",Q,[a("div",Z,s(e.name||e.device_code),1),a("div",aa,s(e.device_code),1)]),a("span",{class:T(["badge",e.online?"badge--live":"badge--danger"])},[a("span",{class:T(["dot",e.online?"dot--pulse":""])},null,2),r(" "+s(e.online?"Online":"Offline"),1)],2)]),a("ul",ta,[a("li",null,[t[8]||(t[8]=a("span",null,"Lokasi",-1)),a("strong",null,s(e.location||"—"),1)]),a("li",null,[t[9]||(t[9]=a("span",null,"Profil",-1)),a("strong",null,s(e.profile_code||"—"),1)]),a("li",null,[t[10]||(t[10]=a("span",null,"Terakhir terlihat",-1)),a("strong",ea,s(e.last_seen?C(R)(e.last_seen_ago):"belum pernah"),1)]),a("li",null,[t[11]||(t[11]=a("span",null,"IP terakhir",-1)),a("strong",sa,s(e.last_ip||"—"),1)]),a("li",null,[t[12]||(t[12]=a("span",null,"Firmware",-1)),a("strong",na,s(e.firmware||"—"),1)])])]))),128)),!g.value.length&&!p.value?(l(),n("p",ia,"Belum ada perangkat terdaftar.")):P("",!0)])]),a("div",la,[a("div",oa,[a("div",null,[a("div",da,[i(d,{name:"key",size:16}),t[13]||(t[13]=r(" API Key Perangkat",-1))]),t[14]||(t[14]=a("div",{class:"card__subtitle"},[r(" Digunakan sebagai header "),a("span",{class:"mono"},"X-Api-Key"),r(" untuk kirim data sensor ")],-1))]),a("div",ra,[a("button",{class:T(["dev__tab",{"is-on":v.value==="keys"}]),onClick:t[0]||(t[0]=e=>v.value="keys")},"Kunci",2),a("button",{class:T(["dev__tab",{"is-on":v.value==="code"}]),onClick:t[1]||(t[1]=e=>v.value="code")},"Kode",2)])]),v.value==="keys"?(l(),n(m,{key:0},[i(B,{name:"fade"},{default:z(()=>[_.value?(l(),n("div",ua,[i(d,{name:"alert",size:16,style:{color:"var(--amber)",flex:"none","margin-top":"2px"}}),a("div",ca,[t[15]||(t[15]=a("strong",{style:{"font-size":"13px"}},"Simpan API key ini sekarang",-1)),t[16]||(t[16]=a("p",{class:"hint",style:{"margin-top":"2px"}}," Demi keamanan, nilai lengkapnya hanya ditampilkan sekali. ",-1)),a("div",pa,[a("code",_a,s(_.value),1),a("button",{class:"btn btn--sm",onClick:t[2]||(t[2]=e=>E(_.value,"new"))},[i(d,{name:"copy",size:13}),r(" "+s(y.value==="new"?"Tersalin":"Salin"),1)])])]),a("button",{class:"btn btn--icon",onClick:t[3]||(t[3]=e=>_.value=null)},[i(d,{name:"x",size:14})])])):P("",!0)]),_:1}),a("div",va,[O(a("input",{"onUpdate:modelValue":t[4]||(t[4]=e=>f.value=e),class:"input",style:{"max-width":"260px"},placeholder:"Label perangkat (mis. ESP32 Gudang)"},null,512),[[F,f.value]]),a("button",{class:"btn btn--primary",disabled:h.value,onClick:M},[i(d,{name:"plus",size:15}),r(" "+s(h.value?"Membuat…":"Buat API Key"),1)],8,ba)]),a("div",ma,[a("table",ga,[t[18]||(t[18]=a("thead",null,[a("tr",null,[a("th",null,"Label"),a("th",null,"Perangkat"),a("th",null,"Cakupan"),a("th",null,"Dibuat"),a("th",null,"Terakhir dipakai"),a("th")])],-1)),a("tbody",null,[(l(!0),n(m,null,k(A.value,e=>(l(),n("tr",{key:e.id},[a("td",null,[a("span",fa,[i(d,{name:"key",size:13}),a("strong",null,s(e.label),1)])]),a("td",ha,s(e.device_code),1),a("td",null,[(l(!0),n(m,null,k(String(e.scopes||"").split(",").filter(Boolean),u=>(l(),n("span",{key:u,class:"badge",style:{"margin-right":"4px"}},s(u.trim()),1))),128))]),a("td",ya,s(C(L)(e.created_at)),1),a("td",ka,s(e.last_used_at?C(R)(e.last_used_ago):"belum dipakai"),1),a("td",Ta,[a("button",{class:"btn btn--icon",title:"Hapus",onClick:u=>D(e)},[i(d,{name:"trash",size:14})],8,xa)])]))),128)),A.value.length?P("",!0):(l(),n("tr",Aa,[...t[17]||(t[17]=[a("td",{colspan:"6",class:"text-center faint",style:{padding:"24px"}},"Belum ada API key.",-1)])]))])])])],64)):(l(),n("div",Pa,[(l(),n(m,null,k(K,(e,u)=>a("div",{key:u,class:"dev__code"},[a("div",Sa,[a("span",wa,s({curl:"Kirim data (cURL)",poll:"Ambil status aktuator",arduino:"Arduino / ESP32",python:"Python",modbus:"Modbus TCP"}[u]),1),a("button",{class:"btn btn--sm",onClick:Na=>E(e,u)},[i(d,{name:"copy",size:13}),r(" "+s(y.value===u?"Tersalin":"Salin"),1)],8,Ca)]),a("pre",Ea,[a("code",null,s(e),1)])])),64))]))]),a("div",Ia,[a("div",Ra,[a("div",null,[a("div",Ka,[i(d,{name:"info",size:16}),t[19]||(t[19]=r(" Ringkasan Endpoint",-1))]),t[20]||(t[20]=a("div",{class:"card__subtitle"},"Kontrak integrasi untuk perangkat IoT / gateway Modbus",-1))])]),t[21]||(t[21]=U('<div class="table-wrap" data-v-3a227fb2><table class="table" data-v-3a227fb2><thead data-v-3a227fb2><tr data-v-3a227fb2><th data-v-3a227fb2>Endpoint</th><th data-v-3a227fb2>Metode</th><th data-v-3a227fb2>Autentikasi</th><th data-v-3a227fb2>Kegunaan</th></tr></thead><tbody data-v-3a227fb2><tr data-v-3a227fb2><td class="mono" data-v-3a227fb2>/api/sensor/ingest</td><td data-v-3a227fb2><span class="badge badge--info" data-v-3a227fb2>POST</span></td><td data-v-3a227fb2><span class="badge badge--busy" data-v-3a227fb2>X-Api-Key</span></td><td class="muted" data-v-3a227fb2>Kirim data sensor + status aktuator</td></tr><tr data-v-3a227fb2><td class="mono" data-v-3a227fb2>/api/sensor/poll</td><td data-v-3a227fb2><span class="badge badge--info" data-v-3a227fb2>GET</span></td><td data-v-3a227fb2><span class="badge badge--busy" data-v-3a227fb2>?api_key=</span></td><td class="muted" data-v-3a227fb2>Ambil perintah heater/fan dari dashboard</td></tr><tr data-v-3a227fb2><td class="mono" data-v-3a227fb2>/api/health</td><td data-v-3a227fb2><span class="badge badge--live" data-v-3a227fb2>GET</span></td><td data-v-3a227fb2><span class="badge" data-v-3a227fb2>publik</span></td><td class="muted" data-v-3a227fb2>Cek koneksi server</td></tr><tr data-v-3a227fb2><td class="mono" data-v-3a227fb2>/api/state</td><td data-v-3a227fb2><span class="badge badge--live" data-v-3a227fb2>GET</span></td><td data-v-3a227fb2><span class="badge" data-v-3a227fb2>session</span></td><td class="muted" data-v-3a227fb2>Snapshot dashboard lengkap</td></tr><tr data-v-3a227fb2><td class="mono" data-v-3a227fb2>/api/controls</td><td data-v-3a227fb2><span class="badge badge--warn" data-v-3a227fb2>POST</span></td><td data-v-3a227fb2><span class="badge" data-v-3a227fb2>session</span></td><td class="muted" data-v-3a227fb2>Kontrol heater/fan dari web</td></tr></tbody></table></div>',1))])]))}},Wa=N(Ma,[["__scopeId","data-v-3a227fb2"]]);export{Wa as default};

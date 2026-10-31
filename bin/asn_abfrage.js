'use strict';
// Audioserver NG - Abfrage IM Container von sonn core (node -e, ueber "docker exec -i").
// Der LoxBerry erreicht ein macvlan-Kind nicht ueber das Netz; deshalb laeuft diese Abfrage im Container gegen
// 127.0.0.1:7090. Eingabe ueber stdin (JSON): {"auftrag":"stand"} oder {"auftrag":"rescan","benutzer":..,"kennwort":..}.
// Ausgabe: EINE JSON-Zeile auf stdout. Nie Kennwort, Token, Seriennummer des Miniservers oder Zugangsdaten aus
// config.json ausgeben - aus config.json werden nur macId, ip, paired, loxoneEnabled, mqtt.enabled und die Zahl
// der Konten (system.users.length) gelesen.
// Wege (aus dem Quelltext von sonn core 4.0.0-beta.22 gelesen): GET /api/v1/health, GET /api/v1/zones (ohne
// Anmeldung), POST /admin/api/auth/login -> token, POST /admin/api/content/library/rescan mit Bearer.
const fs = require('fs');
let roh = '';
let zuviel = false;
process.stdin.setEncoding('utf8');
process.stdin.on('data', (d) => { if (roh.length + d.length <= 65536) { roh += d; } else { zuviel = true; } });
process.stdin.on('end', () => {
  haupt().catch((e) => aus({ ok: false, grund: 'SONN_FEHLER', text: String((e && e.message) || e).slice(0, 160) }));
});
function aus(o) { process.stdout.write(JSON.stringify(o) + '\n'); }
async function hol(pfad, opt) {
  const c = new AbortController();
  const t = setTimeout(() => c.abort(), 5000);
  try {
    const r = await fetch('http://127.0.0.1:7090' + pfad, Object.assign({ signal: c.signal }, opt || {}));
    let j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    return { code: r.status, json: j };
  } finally {
    clearTimeout(t);
  }
}
function text(v, n) { return (typeof v === 'string' || typeof v === 'number') ? String(v).slice(0, n || 120) : ''; }
// Geraetetest 31.10.2026 22:30, Befund B: sonn (zoneProjection.js toSource) liefert source.name nur fuer Radio-Stationen
// (state.station) und Dienste mit Bezeichnung; ein TuneIn-Sender kommt als kind "track" mit name "" und
// id "tunein:station:<base64 von 'sender:nummer'>". Dann: der Dienst aus dem Anfang der Kennung, bei TuneIn mit dem
// Sendernamen aus der Kennung (nur Buchstaben, Ziffern, . _ -). Weitere Teile der Kennung gehen nie hinaus.
const DIENSTE = { tunein: 'TuneIn', spotify: 'Spotify', airplay: 'AirPlay', bluetooth: 'Bluetooth', linein: 'Line-In',
  library: 'Bibliothek', local: 'Bibliothek', musicassistant: 'Music Assistant', radio: 'Radio' };
function quelle(q) {
  if (!q || typeof q !== 'object') { return ''; }
  const nm = text(q.name);
  if (nm !== '') { return nm; }
  const id = typeof q.id === 'string' ? q.id : '';
  const t = /^([a-z0-9]{1,20}):/i.exec(id);
  if (!t) { return q.kind === 'radio' ? 'Radio' : ''; }
  const dienst = DIENSTE[t[1].toLowerCase()] || t[1];
  const st = /^tunein:station:(?:b64_)?([A-Za-z0-9+\/=_-]{1,200})$/.exec(id);   // gemessen: "tunein:station:b64_<base64>"
  if (st) {
    let roh = '';
    try { roh = Buffer.from(st[1].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8'); } catch (e) { roh = ''; }
    const sender = /^([A-Za-z0-9._-]{1,40})(:|$)/.exec(roh);
    if (sender) { return dienst + ' (' + sender[1] + ')'; }
  }
  return dienst;
}
async function haupt() {
  // Fremdpruefung 31.10.2026, T3-4: eine abgeschnittene, leere oder unlesbare Eingabe oder ein unbekannter Auftrag ist
  // ein Fehler - bis dahin fiel sie auf {} zurueck, und ein kaputter "rescan" meldete als "stand" ok:true.
  let ein = null;
  try { ein = zuviel ? null : JSON.parse(roh); } catch (e) { ein = null; }
  if (!ein || typeof ein !== 'object' || (ein.auftrag !== 'stand' && ein.auftrag !== 'rescan')) {
    return aus({ ok: false, grund: 'SONN_FEHLER', text: 'Eingabe unlesbar' });
  }
  if (ein.auftrag === 'rescan') {
    let a;
    try {
      a = await hol('/admin/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: String(ein.benutzer || ''), password: String(ein.kennwort || '') }) });
    } catch (e) {
      return aus({ ok: false, grund: 'SONN_ANTWORTET_NICHT' });
    }
    const tok = a.json && typeof a.json.token === 'string' ? a.json.token : '';
    if (a.code === 401 || a.code === 403 || (a.code === 200 && tok === '')) { return aus({ ok: false, grund: 'KONTO_FALSCH' }); }
    if (a.code !== 200) { return aus({ ok: false, grund: 'ANMELDUNG|' + a.code }); }
    const r = await hol('/admin/api/content/library/rescan', { method: 'POST', headers: { Authorization: 'Bearer ' + tok } });
    if (r.code >= 200 && r.code < 300) { return aus({ ok: true, grund: '', code: r.code }); }
    return aus({ ok: false, grund: 'RESCAN|' + r.code });
  }
  let h, z;
  try {
    h = await hol('/api/v1/health');
    z = await hol('/api/v1/zones');
  } catch (e) {
    return aus({ ok: false, grund: 'SONN_ANTWORTET_NICHT' });
  }
  const hj = h.json && typeof h.json === 'object' ? h.json : {};
  const checks = Array.isArray(hj.checks) ? hj.checks.slice(0, 8).map((c) => ({
    name: text(c && c.name, 20), status: text(c && c.status, 20), detail: text(c && c.detail, 160) })) : [];
  let s = {};
  let m = {};
  let k = null;
  // Geraetetest 31.10.2026 22:30, Befund A: bei Google Cast nennt /api/v1/zones nur output.protocol ("googleCast"),
  // keinen Geraetenamen - den traegt allein config.json unter zones[].output.name (am LoxBerry gelesen). Daraus wird
  // NUR der Name uebernommen, nie host (IP) oder sourceMac/sourceSerial.
  const ausgangName = {};
  try {
    const c = JSON.parse(fs.readFileSync('/app/data/config.json', 'utf8'));
    s = (c && c.system && c.system.audioserver) || {};
    m = (c && c.mqtt) || {};
    // Nur die ZAHL der Konten (Schritt 1 "So geht es weiter"), nie Namen oder Kennwoerter.
    k = (c && c.system && Array.isArray(c.system.users)) ? c.system.users.length : null;
    (Array.isArray(c && c.zones) ? c.zones : []).slice(0, 64).forEach((cz) => {
      if (cz && cz.output && typeof cz.output.name === 'string') { ausgangName[Number(cz.id)] = text(cz.output.name); }
    });
  } catch (e) { s = {}; m = {}; k = null; }
  const zonen = (z.json && Array.isArray(z.json.zones) ? z.json.zones : []).slice(0, 24).map((x) => ({
    id: Number(x && x.id), name: text(x && x.name), state: text(x && x.state, 20),
    volume: x && typeof x.volume === 'number' ? x.volume : null,
    track: { title: text(x && x.track && x.track.title), artist: text(x && x.track && x.track.artist) },
    source: { name: quelle(x && x.source) },
    // output: null = kein Ausgang eingerichtet; output.device.connected gibt es nur bei Squeezelite (sonn
    // apiZoneResolvers.js resolveOutputDevice), bei Google Cast fehlt es -> null (keine Aussage). Die Kennung des
    // Geraets (output.device.id, eine MAC) wird nie weitergegeben. error: Wiedergabefehler der Zone (zoneProjection.js).
    // Name: output.device.name, sonst (Google Cast) der Name aus config.json.
    output: (x && x.output === null) ? null : ((x && x.output && typeof x.output === 'object') ? { device: {
      name: text(x.output.device && x.output.device.name) || (ausgangName[Number(x.id)] || ''),
      connected: (x.output.device && typeof x.output.device.connected === 'boolean') ? x.output.device.connected : null } } : undefined),
    error: !!(x && typeof x.error === 'string' && x.error !== '') }));
  aus({
    ok: true, grund: '',
    health: { code: h.code, status: text(hj.status, 20), version: text(hj.version, 40), checks: checks },
    zonen_code: z.code, zonen: zonen,
    mac_id: text(s.macId, 20), ip: text(s.ip, 20), gekoppelt: s.paired === true && s.loxoneEnabled !== false,
    loxone_ein: s.loxoneEnabled === true, mqtt_ein: m.enabled === true, konten: k,
  });
}

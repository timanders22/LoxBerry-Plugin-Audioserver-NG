'use strict';
// Audioserver NG - Abfrage IM Container von sonn core (node -e, ueber "docker exec -i").
// Der LoxBerry erreicht ein macvlan-Kind nicht ueber das Netz; deshalb laeuft diese Abfrage im Container gegen
// 127.0.0.1:7090. Eingabe ueber stdin (JSON): {"auftrag":"stand"} oder {"auftrag":"rescan","benutzer":..,"kennwort":..}.
// Ausgabe: EINE JSON-Zeile auf stdout. Nie Kennwort, Token, Seriennummer des Miniservers oder Zugangsdaten aus
// config.json ausgeben - aus config.json werden nur macId, ip, paired, loxoneEnabled, mqtt.enabled und die Zahl
// der Konten (system.users.length) gelesen.
// Wege (aus dem Quelltext von sonn core 4.0.0-beta.22 gelesen): GET /api/v1/health, GET /api/v1/zones (ohne
// Anmeldung), POST /admin/api/auth/login -> token, POST /admin/api/content/library/rescan mit Bearer.
// 0.9.2 (01.11.2026): {"auftrag":"favoriten","zonen":[..],"eintraege":[{nr,art,name,quelle}]} ersetzt die
// Zonenfavoriten NUR ueber die dokumentierte oeffentliche API /api/v1 (INTEGRATING.md: GET/POST/PATCH/DELETE
// /zones/{id}/favorites, GET /browse, GET /playlists) - keine Datei von sonn wird angefasst.
// 0.9.3 (02.11.2026): {"auftrag":"tunein","ziel":"lesen|ausblenden|zeigen"} ist die EINZIGE Stelle, die eine Datei von sonn aendert - nur auf
// Einstellung, nur den erkannten Block (siehe unten). Die Favoriten legen zusaetzlich Custom Streams an (Verwaltungs-API, sonn-Konto noetig).
const fs = require('fs');
const vm = require('vm');
const crypto = require('crypto');
let CUSTOM = null;   // 0.9.3: Ergebnis von Schritt 0, geht mit jeder Favoriten-Antwort hinaus
let roh = '';
let zuviel = false;
process.stdin.setEncoding('utf8');
process.stdin.on('data', (d) => { if (roh.length + d.length <= 65536) { roh += d; } else { zuviel = true; } });
process.stdin.on('end', () => {
  haupt().catch((e) => aus({ ok: false, grund: 'SONN_FEHLER', text: String((e && e.message) || e).slice(0, 160) }));
});
function aus(o) {
  if (CUSTOM !== null && o && o.favoriten) { o.custom = CUSTOM; }
  process.stdout.write(JSON.stringify(o) + '\n');
}
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
// ---------------- 0.9.2: Favoritenliste (Wunsch Hausherr 01.11.2026) ----------------
// Belege (sonn core 4.0.0-beta.22, Quelltext dist/ im Abbild und INTEGRATING.md im Repository sonn-audio/core):
//  - Zonenfavoriten = "Meine Favoriten" der Loxone App; der Eingang Fav des Audio Players (audio/<z>/roomfav/play/<n>)
//    spielt den Favoriten mit der KENNUNG n (zoneHandlers.js audioFavoritePlay -> favoritesManager.getForPlayback:
//    items.find(i => i.id === n)) - nicht den n-ten Platz.
//  - POST /api/v1/zones/{z}/favorites vergibt die Kennung als hoechste vorhandene + 1, bei leerer Liste 1
//    (favoritesManager.add: nextId = max(id) + 1); DELETE entfernt nur diesen Eintrag, die anderen behalten ihre
//    Kennung (remove: filter + slot neu). Eine Kennung frei setzen kann /api/v1 nicht.
//  => Stabile Nummern: Liste leeren, dann 1..hoechste Nr der Reihe nach anlegen - fuer eine Luecke einen Platzhalter,
//     der danach wieder entfernt wird. Jede vergebene Kennung wird gegen die Soll-Nummer geprueft; weicht eine ab
//     (sonn hat sein Verhalten geaendert), endet der Lauf sofort mit NUMMER.
//  - Ordner/Datei der Bibliothek: ueber GET /api/v1/browse Ordner fuer Ordner; die Kennung des Treffers geht
//    unveraendert als uri an POST (INTEGRATING.md: ids "round-trip exactly").
//    Wiedergabeliste: GET /api/v1/playlists (Name), deren id ist eine Browse-Kennung.
//  - 0.9.2 Nachzug (Geraetebefund 01.11.2026 03:15): der Musikordner haengt jetzt nur lesend als Unterordner der
//    Lokalen Bibliothek im Container (data/music/local/<ordner>, ein.ordner, Vorgabe LoxBerry-Musik). Gesucht wird
//    deshalb NUR dort: Library -> "Local Media" -> "Folders" -> <ordner> -> Pfad. Am Geraet nur lesend gemessen
//    (01.11.2026, beta.22): Wurzel [Library, Radio]; Library -> [Local Media, alerts, Event_Sounds(, Freigaben)];
//    Local Media -> [Albums, Artists, Tracks, Folders, Playlists]; die Kennungen sind b1.c.<art>.<b64 Dienst>.<b64
//    Ordner> mit den Ordnern library-local, library-local-folders, library-local-tracks (localLibraryProvider.js,
//    browseRef.js). Erkannt wird ueber diese Ordnerkennung, hilfsweise ueber den Namen.
const FAV_PLATZHALTER = 'http://127.0.0.1:9/audioserverng-platzhalter';
const FAV_NAME_PLATZHALTER = 'Audioserver NG Platzhalter';
// 0.9.2 Nachzug: Gesamtfrist fuer den Bibliotheksstand (0 = keine) - der "stand" darf ASN_ABFRAGE_S (20 s) nie reissen.
let FRIST_ENDE = 0;
async function api(methode, pfad, koerper, ms) {
  let grenze = ms || 15000;
  if (FRIST_ENDE > 0) {
    const rest = FRIST_ENDE - Date.now();
    if (rest <= 0) { throw new Error('Frist'); }
    grenze = Math.min(grenze, rest);
  }
  const c = new AbortController();
  const t = setTimeout(() => c.abort(), grenze);
  try {
    const opt = { method: methode, signal: c.signal };
    if (koerper !== undefined) {
      opt.headers = { 'Content-Type': 'application/json' };
      opt.body = JSON.stringify(koerper);
    }
    const r = await fetch('http://127.0.0.1:7090/api/v1' + pfad, opt);
    let j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    return { code: r.status, json: j };
  } finally {
    clearTimeout(t);
  }
}
// Alle Eintraege einer Browse-Ebene (seitenweise, hoechstens 5000).
async function ebene(id) {
  const aus = [];
  for (let start = 0; start < 5000; start += 500) {
    const r = await api('GET', (id === '' ? '/browse' : '/browse/' + encodeURIComponent(id)) + '?start=' + start + '&limit=500');
    if (r.code !== 200 || !r.json || !Array.isArray(r.json.items)) { return start === 0 ? null : aus; }
    r.json.items.forEach((x) => { if (x && typeof x.id === 'string') { aus.push(x); } });
    const total = typeof r.json.total === 'number' ? r.json.total : null;
    if (r.json.items.length < 500 || (total !== null && aus.length >= total)) { break; }
  }
  return aus;
}
// Den Namen in einer Ebene finden: zuerst genau, sonn ohne Gross/klein - aber nur, wenn das eindeutig ist.
function finde(liste, name, pruef) {
  const k = liste.filter(pruef);
  const genau = k.filter((x) => x.name === name);
  if (genau.length) { return genau[0]; }
  const klein = k.filter((x) => typeof x.name === 'string' && x.name.toLowerCase() === name.toLowerCase());
  return klein.length === 1 ? klein[0] : null;
}
// Der Ordner einer Container-Kennung (b1.c.<art>.<b64 Dienst>.<b64 Ordner>) - oder ''.
function ordnerVon(id) {
  const t = String(id || '').split('.');
  if (t.length !== 5 || t[0] !== 'b1' || t[1] !== 'c') { return ''; }
  try { return Buffer.from(t[4].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8'); } catch (e) { return ''; }
}
// Einen Eintrag ueber seine Ordnerkennung finden, hilfsweise ueber den Namen (sonn benennt englisch und fest).
function eintrag(liste, ordner, name) {
  if (!liste) { return null; }
  return liste.find((x) => ordnerVon(x.id) === ordner) || liste.find((x) => x.name === name && x.browsable !== false) || null;
}
// Die Lokale Bibliothek: Kennung von "Local Media" (null = nicht gefunden, sonn antwortet aber).
async function lokaleBibliothek() {
  const root = await ebene('');
  if (root === null) { return null; }
  const lib = root.find((x) => x.service === 'library' && x.browsable !== false);
  if (!lib) { return null; }
  return eintrag(await ebene(lib.id), 'library-local', 'Local Media');
}
// Der Name des Musikordners in der Lokalen Bibliothek (vom Plugin, nur Buchstaben, Ziffern, . _ -).
function musikOrdner(ein) {
  const o = ein && typeof ein.ordner === 'string' ? ein.ordner : '';
  return /^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/.test(o) ? o : 'LoxBerry-Musik';
}
// Die Ordner-Wurzel fuer liste/datei: Local Media -> Folders -> <ordner>. Liste mit hoechstens einer Kennung.
async function ordnerWurzeln(ein) {
  const lm = await lokaleBibliothek();
  if (!lm) { return []; }
  const f = eintrag(await ebene(lm.id), 'library-local-folders', 'Folders');
  if (!f) { return []; }
  const kinder = await ebene(f.id);
  const o = kinder ? kinder.find((x) => x.name === musikOrdner(ein) && x.browsable === true) : null;
  return o ? [o.id] : [];
}
// Stand der Bibliothek fuer "So geht es weiter" (4) und den Reiter Test: Titel der Lokalen Bibliothek (Tracks, total)
// und ob der Ordner <ordner> unter Folders steht. Jeder Fehler -> null (nicht feststellbar), nie ein Abbruch.
async function bibliothekStand(ein) {
  const aus = { titel: null, musik_sichtbar: null };
  FRIST_ENDE = Date.now() + 6000;
  try {
    const lm = await lokaleBibliothek();
    if (!lm) { return aus; }
    const kat = await ebene(lm.id);
    const tr = eintrag(kat, 'library-local-tracks', 'Tracks');
    if (tr) {
      const r = await api('GET', '/browse/' + encodeURIComponent(tr.id) + '?start=0&limit=1', undefined, 5000);
      if (r.code === 200 && r.json && Number.isInteger(r.json.total) && r.json.total >= 0) { aus.titel = r.json.total; }
    }
    const f = eintrag(kat, 'library-local-folders', 'Folders');
    if (f) {
      const kinder = await ebene(f.id);
      if (kinder) { aus.musik_sichtbar = kinder.some((x) => x.name === musikOrdner(ein) && x.browsable === true); }
    }
  } catch (e) {
    return { titel: null, musik_sichtbar: null };
  } finally {
    FRIST_ENDE = 0;
  }
  return aus;
}
async function bibliothekFinden(wurzeln, pfad, art) {
  const teile = pfad.split('/').filter((x) => x !== '' && x !== '.');
  for (const w of wurzeln) {
    let id = w;
    let treffer = null;
    for (let i = 0; i < teile.length; i++) {
      const liste = await ebene(id);
      if (!liste) { treffer = null; break; }
      const letzter = i === teile.length - 1;
      let x;
      if (!letzter || art === 'liste') {
        x = finde(liste, teile[i], (y) => y.browsable === true);
      } else {
        // datei: sonn nennt eine Datei mit dem Titel aus den Tags, ohne Tags mit dem Dateinamen ohne Endung.
        const ohne = teile[i].replace(/\.[A-Za-z0-9]{1,5}$/, '');
        const t = (y) => y.browsable !== true && y.playable !== false;
        x = finde(liste, teile[i], t) || finde(liste, ohne, t);
      }
      if (!x) { treffer = null; break; }
      id = x.id;
      treffer = x;
    }
    if (treffer && (art !== 'liste' || treffer.playable !== false)) { return treffer.id; }
  }
  return '';
}
// 0.9.3, Schritt 0 der Favoriten: fehlende Radio-Favoriten als Custom Stream anlegen (Belege: sonn core 4.0.0-beta.22, dist/adapters/http/adminApi/content/
// contentHandlers.js: GET /admin/api/content/radio/custom -> {stations:[{id,name,stream}]}, POST {name,stream} -> 201 {station}). Vergleich ueber die Adresse ohne
// Gross-/Kleinschreibung. Nichts wird geloescht oder umbenannt. Rueckgabe null = kein Radio-Eintrag, sonst {angelegt, vorhanden, fehler}; wirft nie.
async function customStreams(ein, soll) {
  const radios = Array.from(soll.values()).filter((e) => e.art === 'radio' && /^https?:\/\//i.test(e.quelle));
  if (radios.length === 0) { return null; }
  const erg = { angelegt: 0, vorhanden: 0, fehler: '' };
  if (typeof ein.benutzer !== 'string' || ein.benutzer === '' || typeof ein.kennwort !== 'string' || ein.kennwort === '') {
    erg.fehler = 'KONTO_FEHLT';
    return erg;
  }
  try {
    const a = await hol('/admin/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: ein.benutzer, password: ein.kennwort }) });
    const tok = a.json && typeof a.json.token === 'string' ? a.json.token : '';
    if (a.code === 401 || a.code === 403 || (a.code === 200 && tok === '')) { erg.fehler = 'KONTO_FALSCH'; return erg; }
    if (a.code !== 200) { erg.fehler = 'ANMELDUNG|' + a.code; return erg; }
    const l = await hol('/admin/api/content/radio/custom', { headers: { Authorization: 'Bearer ' + tok } });
    if (l.code !== 200 || !l.json || !Array.isArray(l.json.stations)) { erg.fehler = 'LISTE|' + l.code; return erg; }
    const da = new Set(l.json.stations.map((x) => (x && typeof x.stream === 'string' ? x.stream.trim().toLowerCase() : '')));
    for (const r of radios) {
      const schluessel = r.quelle.trim().toLowerCase();
      if (da.has(schluessel)) { erg.vorhanden++; continue; }
      const p = await hol('/admin/api/content/radio/custom', { method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok },
        body: JSON.stringify({ name: r.name, stream: r.quelle }) });
      if (p.code >= 200 && p.code <= 299) {
        erg.angelegt++;
        da.add(schluessel);
      } else if (erg.fehler === '') {
        erg.fehler = 'ANLEGEN|' + p.code;
      }
    }
  } catch (e) {
    if (erg.fehler === '') { erg.fehler = 'NETZ'; }
  }
  return erg;
}
async function favoriten(ein) {
  const roh = Array.isArray(ein.eintraege) ? ein.eintraege.slice(0, 99) : [];
  const soll = new Map();
  for (const e of roh) {
    const nr = e && Number.isInteger(e.nr) ? e.nr : 0;
    if (nr < 1 || nr > 99 || soll.has(nr) || !['radio', 'liste', 'datei'].includes(e.art)
        || typeof e.name !== 'string' || typeof e.quelle !== 'string' || e.quelle === '') {
      return aus({ ok: false, grund: 'FAV_EINGABE', favoriten: true });
    }
    soll.set(nr, { nr: nr, art: e.art, name: e.name.slice(0, 80) || e.quelle.slice(0, 80), quelle: e.quelle.slice(0, 400) });
  }
  if (soll.size === 0) { return aus({ ok: false, grund: 'FAV_LEER', favoriten: true }); }
  let z;
  try { z = await api('GET', '/zones'); } catch (e) { return aus({ ok: false, grund: 'SONN_ANTWORTET_NICHT', favoriten: true }); }
  const da = (z.json && Array.isArray(z.json.zones) ? z.json.zones : []).map((x) => Number(x && x.id))
    .filter((x) => Number.isInteger(x) && x >= 1 && x <= 24);
  let zonen = Array.isArray(ein.zonen) ? ein.zonen.filter((x) => Number.isInteger(x)) : [];
  if (zonen.length === 0) { zonen = da.slice(); }
  if (zonen.length === 0) { return aus({ ok: false, grund: 'FAV_KEINE_ZONE', favoriten: true }); }
  const fehlt = zonen.filter((x) => !da.includes(x));
  if (fehlt.length) { return aus({ ok: false, grund: 'FAV_ZONE_FEHLT|' + fehlt.join(','), favoriten: true }); }
  // 1. Alles aufloesen, BEVOR etwas geaendert wird.
  const uebersprungen = [];
  const uri = new Map();
  let wurzeln = null;
  let listen = null;
  try {
    for (const e of soll.values()) {
      if (/^https?:\/\//i.test(e.quelle)) {
        if (e.art === 'liste') { uebersprungen.push({ nr: e.nr, grund: 'FAV_ART' }); continue; }
        uri.set(e.nr, e.quelle);
        continue;
      }
      if (e.art === 'radio') { uebersprungen.push({ nr: e.nr, grund: 'FAV_ART' }); continue; }
      if (wurzeln === null) { wurzeln = await ordnerWurzeln(ein); }
      let id = wurzeln.length ? await bibliothekFinden(wurzeln, e.quelle, e.art) : '';
      if (id === '' && e.art === 'liste' && e.quelle.indexOf('/') < 0) {
        if (listen === null) {
          const p = await api('GET', '/playlists?limit=500');
          listen = p.code === 200 && p.json && Array.isArray(p.json.items) ? p.json.items : [];
        }
        const pl = finde(listen, e.quelle, (y) => y && typeof y.id === 'string');
        id = pl ? pl.id : '';
      }
      if (id === '') { uebersprungen.push({ nr: e.nr, grund: wurzeln.length ? 'FAV_NICHT_GEFUNDEN' : 'FAV_KEINE_BIBLIOTHEK' }); continue; }
      uri.set(e.nr, id);
    }
  } catch (e) {
    return aus({ ok: false, grund: 'SONN_ANTWORTET_NICHT', favoriten: true });
  }
  if (uri.size === 0) { return aus({ ok: false, grund: 'FAV_NICHTS', uebersprungen: uebersprungen, favoriten: true }); }
  // 0.9.3, Schritt 0: Custom Streams, nach dem Aufloesen und vor dem Leeren der Zonenfavoriten. Scheitert er, laufen die Favoriten weiter.
  CUSTOM = await customStreams(ein, soll);
  const hoechste = Math.max(...uri.keys());
  const ergebnis = [];
  // 2. Je Zone: alte Liste merken, leeren, der Reihe nach anlegen, Platzhalter entfernen, nachlesen.
  for (const zone of zonen) {
    const zs = { zone: zone, vorher: [], nachher: [], gesetzt: 0 };
    ergebnis.push(zs);
    try {
      const v = await api('GET', '/zones/' + zone + '/favorites?limit=500');
      if (v.code !== 200 || !v.json || !Array.isArray(v.json.items)) {
        return aus({ ok: false, grund: 'FAV_LESEN|' + zone + '|' + v.code, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
      }
      zs.vorher = v.json.items.map((x) => ({ id: x.id, name: text(x.name, 120), source: text(x.source, 400) }));
      for (const x of v.json.items) {
        const d = await api('DELETE', '/zones/' + zone + '/favorites', { id: x.id });
        if (d.code < 200 || d.code > 299) {
          return aus({ ok: false, grund: 'FAV_LEEREN|' + zone + '|' + d.code, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
        }
      }
      const l = await api('GET', '/zones/' + zone + '/favorites?limit=500');
      if (l.code !== 200 || !l.json || !Array.isArray(l.json.items) || l.json.items.length !== 0) {
        return aus({ ok: false, grund: 'FAV_LEEREN|' + zone + '|' + l.code, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
      }
      const platz = [];
      for (let n = 1; n <= hoechste; n++) {
        const echt = uri.has(n);
        const name = echt ? soll.get(n).name : FAV_NAME_PLATZHALTER;
        const a = await api('POST', '/zones/' + zone + '/favorites', { uri: echt ? uri.get(n) : FAV_PLATZHALTER, name: name }, 30000);
        const id = a.json && Number.isInteger(a.json.id) ? a.json.id : null;
        if (a.code < 200 || a.code > 299 || id === null) {
          return aus({ ok: false, grund: 'FAV_ANLEGEN|' + zone + '|' + n + '|' + a.code, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
        }
        if (id !== n) {
          return aus({ ok: false, grund: 'FAV_NUMMER|' + zone + '|' + n + '|' + id, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
        }
        if (!echt) { platz.push(id); continue; }
        zs.gesetzt++;
        if (a.json.name !== name) {
          // sonn uebernimmt beim Anlegen den Titel aus den Metadaten (z. B. TuneIn) - den eigenen Namen setzen.
          const p = await api('PATCH', '/zones/' + zone + '/favorites', { id: id, name: name });
          if (p.code < 200 || p.code > 299) {
            return aus({ ok: false, grund: 'FAV_NAME|' + zone + '|' + n + '|' + p.code, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
          }
        }
      }
      for (const id of platz) {
        const d = await api('DELETE', '/zones/' + zone + '/favorites', { id: id });
        if (d.code < 200 || d.code > 299) {
          return aus({ ok: false, grund: 'FAV_PLATZHALTER|' + zone + '|' + id + '|' + d.code, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
        }
      }
      const e = await api('GET', '/zones/' + zone + '/favorites?limit=500');
      const ist = e.code === 200 && e.json && Array.isArray(e.json.items) ? e.json.items : null;
      if (ist === null) {
        return aus({ ok: false, grund: 'FAV_LESEN|' + zone + '|' + e.code, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
      }
      zs.nachher = ist.map((x) => ({ id: x.id, name: text(x.name, 120) }));
      const passt = ist.length === uri.size && ist.every((x) => uri.has(x.id) && x.name === soll.get(x.id).name);
      if (!passt) {
        return aus({ ok: false, grund: 'FAV_ABWEICHUNG|' + zone, zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
      }
    } catch (e) {
      return aus({ ok: false, grund: 'SONN_ANTWORTET_NICHT', zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
    }
  }
  return aus({ ok: true, grund: '', zonen: ergebnis, uebersprungen: uebersprungen, favoriten: true });
}
// ---------------- 0.9.3: Vorgabesender von TuneIn (Wunsch Hausherr 01.11.2026) ----------------
// sonn core 4.0.0-beta.22 zeigt ohne TuneIn-Benutzer drei fest eingebaute Sender (DEMO_STATIONS, getLocalStations in tuneinProvider.js); weder API noch Datei
// schalten sie ab. Geaendert wird genau dieser eine Block und nur, wenn er genau einmal so dasteht wie erwartet und DEMO_STATIONS sonst nur in seiner Definition
// vorkommt. Alles andere (neue Fassung, andere Zeile) heisst "unbekannt": NICHTS wird geschrieben. Die Aenderung vom 01.11.2026 von Hand gilt als "ausgeblendet".
// 0.9.8 (09.11.2026): sonn core 4.0.0-beta.23 hat die Vorgabesender selbst entfernt (sonn #427). Am Abbild gemessen: DEMO_STATIONS kommt in der Datei nicht
// mehr vor, und getLocalStations gibt ohne Benutzer genau "return [];" zurueck (TUNEIN_LEER). Diese Lage heisst "entfallen": es gibt nichts auszublenden,
// geschrieben wird nichts.
const TUNEIN_DATEI = '/app/dist/adapters/content/providers/tunein/tuneinProvider.js';
const TUNEIN_KOPF = '    async getLocalStations() {\n        const username = this.username;\n        if (!username) {\n';
const TUNEIN_HERSTELLER = TUNEIN_KOPF + '            return DEMO_STATIONS;\n';
const TUNEIN_PLUGIN = TUNEIN_KOPF + '            return []; // Audioserver NG: Vorgabesender ausgeblendet\n';
const TUNEIN_HAND = TUNEIN_KOPF + '            return []; // Claude 01.11.2026: Vorgabesender ausgeblendet (Hausherr)\n';
const TUNEIN_LEER = TUNEIN_KOPF + '            return [];\n';
function zaehle(t, s) { return t.split(s).length - 1; }
function tuneinBefund(t) {
  const her = zaehle(t, TUNEIN_HERSTELLER);
  const unser = zaehle(t, TUNEIN_PLUGIN) + zaehle(t, TUNEIN_HAND);
  const demo = zaehle(t, 'DEMO_STATIONS');
  if (her === 1 && unser === 0 && demo === 2) { return 'sichtbar'; }
  if (her === 0 && unser === 1 && demo === 1) { return 'ausgeblendet'; }
  if (her === 0 && unser === 0 && demo === 0 && zaehle(t, TUNEIN_LEER) === 1) { return 'entfallen'; }
  return 'unbekannt';
}
// Nur lesen. lage: sichtbar | ausgeblendet | entfallen | unbekannt | fehlt; sha: Anfang der Pruefsumme; hand: die Aenderung von Hand steht drin.
function tuneinLesen() {
  let b;
  try {
    b = fs.readFileSync(TUNEIN_DATEI);
  } catch (e) {
    return { lage: 'fehlt', sha: '', hand: false };
  }
  const sha = crypto.createHash('sha256').update(b).digest('hex').slice(0, 16);
  const t = b.toString('utf8');
  if (!Buffer.from(t, 'utf8').equals(b)) { return { lage: 'unbekannt', sha: sha, hand: false }; }
  return { lage: tuneinBefund(t), sha: sha, hand: zaehle(t, TUNEIN_HAND) === 1 };
}
function tuneinAuftrag(ein) {
  const antwort = (ok, grund, zusatz) => aus(Object.assign({ ok: ok, grund: grund, tunein_auftrag: true, tunein: tuneinLesen() }, zusatz || {}));
  const ziel = ein.ziel;
  if (ziel !== 'lesen' && ziel !== 'ausblenden' && ziel !== 'zeigen') { return antwort(false, 'TUNEIN_EINGABE'); }
  if (ziel === 'lesen') { return antwort(true, '', { geaendert: false }); }
  const vor = tuneinLesen();
  if (vor.lage === 'fehlt') { return antwort(false, 'TUNEIN_FEHLT'); }
  if (vor.lage === 'unbekannt') { return antwort(false, 'TUNEIN_UNBEKANNT'); }
  if (vor.lage === 'entfallen') { return antwort(true, '', { geaendert: false }); }   // sonn zeigt selbst keine mehr: nichts zu schreiben
  const soll = ziel === 'ausblenden' ? 'ausgeblendet' : 'sichtbar';
  if (vor.lage === soll) { return antwort(true, '', { geaendert: false }); }
  const tmp = TUNEIN_DATEI + '.asn_neu_' + process.pid;
  try {
    const alt = fs.readFileSync(TUNEIN_DATEI, 'utf8');
    const von = ziel === 'ausblenden' ? TUNEIN_HERSTELLER : (vor.hand ? TUNEIN_HAND : TUNEIN_PLUGIN);
    const nach = ziel === 'ausblenden' ? TUNEIN_PLUGIN : TUNEIN_HERSTELLER;
    const neu = alt.replace(von, () => nach);
    if (tuneinBefund(neu) !== soll) { return antwort(false, 'TUNEIN_UNBEKANNT'); }
    try {
      new vm.Script(neu, { filename: 'tuneinProvider.js' });   // nur uebersetzen, nicht ausfuehren
    } catch (e) {
      return antwort(false, 'TUNEIN_SYNTAX');
    }
    // Sicherung wie am 01.11.2026 von Hand: neben der Datei, wird nie ueberschrieben.
    const sich = TUNEIN_DATEI + '.vor_audioserverng_' + vor.sha;
    if (!fs.existsSync(sich)) { fs.copyFileSync(TUNEIN_DATEI, sich, fs.constants.COPYFILE_EXCL); }
    if (!fs.readFileSync(sich).equals(fs.readFileSync(TUNEIN_DATEI))) { return antwort(false, 'TUNEIN_SICHERUNG'); }
    const st = fs.statSync(TUNEIN_DATEI);
    fs.writeFileSync(tmp, neu, { mode: st.mode & 0o777 });
    try { fs.chownSync(tmp, st.uid, st.gid); } catch (e) { /* nur Besitzer, die Datei bleibt lesbar */ }
    fs.renameSync(tmp, TUNEIN_DATEI);
  } catch (e) {
    try { fs.unlinkSync(tmp); } catch (e2) { /* gab es nicht */ }
    return antwort(false, 'TUNEIN_SCHREIBEN', { text: text(e && e.code, 40) });
  }
  const nachher = tuneinLesen();
  return antwort(nachher.lage === soll, nachher.lage === soll ? '' : 'TUNEIN_SCHREIBEN', { geaendert: true });
}
async function haupt() {
  // Fremdpruefung 31.10.2026, T3-4: eine abgeschnittene, leere oder unlesbare Eingabe oder ein unbekannter Auftrag ist
  // ein Fehler - bis dahin fiel sie auf {} zurueck, und ein kaputter "rescan" meldete als "stand" ok:true.
  let ein = null;
  try { ein = zuviel ? null : JSON.parse(roh); } catch (e) { ein = null; }
  if (!ein || typeof ein !== 'object' || (ein.auftrag !== 'stand' && ein.auftrag !== 'rescan' && ein.auftrag !== 'favoriten' && ein.auftrag !== 'tunein')) {
    return aus({ ok: false, grund: 'SONN_FEHLER', text: 'Eingabe unlesbar' });
  }
  if (ein.auftrag === 'favoriten') {
    return favoriten(ein);
  }
  if (ein.auftrag === 'tunein') {
    return tuneinAuftrag(ein);
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
    // 0.9.3: Standardlautstaerke und Hoechstwert (kommen vom Miniserver, Parameter DefVol/MaxVol des Audio Players).
    volumeLimits: { default: x && x.volumeLimits && typeof x.volumeLimits.default === 'number' ? x.volumeLimits.default : null,
      max: x && x.volumeLimits && typeof x.volumeLimits.max === 'number' ? x.volumeLimits.max : null },
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
  const bib = await bibliothekStand(ein);   // 0.9.2 Nachzug: Titel und Musikordner der Lokalen Bibliothek (null = unklar)
  aus({
    ok: true, grund: '',
    health: { code: h.code, status: text(hj.status, 20), version: text(hj.version, 40), checks: checks },
    zonen_code: z.code, zonen: zonen,
    mac_id: text(s.macId, 20), ip: text(s.ip, 20), gekoppelt: s.paired === true && s.loxoneEnabled !== false,
    loxone_ein: s.loxoneEnabled === true, mqtt_ein: m.enabled === true, konten: k,
    titel: bib.titel, musik_sichtbar: bib.musik_sichtbar,
    tunein: tuneinLesen(),
  });
}

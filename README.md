# Meross für IP-Symcon

Inoffizielles Community-Modul zur **lokalen** Steuerung von Meross-Geräten über das
LAN. Die Meross-Cloud wird **nur einmalig** beim Einrichten genutzt (Geräteliste,
Konto-Key und lokale IPs holen) – im Betrieb läuft alles lokal: **kein MQTT, kein
Dauer-Cloud-Zwang, kein Python**.

## Funktionsumfang
- Lokale Steuerung per signiertem HTTP an `http://<geräte-ip>/config`, Status per Polling.
- **Konfigurator**: erkennt deine Geräte automatisch aus der Cloud und legt die
  passenden Instanzen an (Key und IP vorausgefüllt).
- **Schöne, interaktive Kachel-Visualisierungen** (HTML-SDK) für Thermostat,
  Rollladen und Steckdose – skalieren mit der Kachelgröße, Hell-/Dunkel-Modus.

## Unterstützte Geräte

| Modelle | Typ | Status |
|---|---|---|
| MSS110, MSS210, MSS210P, MSS310, MSS310H, MSS420F, MSS425E, MSS510, MSS550, MSS570, MSS620, MSS710 | Steckdose / Schalter / Leiste (bis 6 Kanäle, inkl. Messwerte) | MSS210P getestet |
| MRS100 | Rollladen | getestet |
| MTS200, MTS200B, MTS215B, MTS960 | Eigenständiger WLAN-Thermostat | MTS200/215B getestet |
| MSL100, MSL120, MSL320, MSL420, MSL430, MSS560 | Lampe / Dimmer / LED (An/Aus, Helligkeit, Farbe, Farbtemperatur) | dokumentiert, zu testen |
| MSG100, MSG200 | Garagentoröffner | dokumentiert, zu testen |
| MSH300 (+ MS100, MS200, MTS100, MTS150) | Hub mit Sensoren & Thermostat-Ventilen | dokumentiert, zu testen |

„getestet" = an echter Hardware bestätigt; „dokumentiert, zu testen" = nach
offiziellem/Community-Protokoll umgesetzt, am eigenen Gerät bitte prüfen.

## Voraussetzungen
- IP-Symcon **ab Version 8.0**.
- Meross-Geräte im selben lokalen Netz; **feste IP** (z. B. in der Fritzbox) empfohlen.
- Meross-Konto (E-Mail + Passwort) für den einmaligen Geräte-Abruf.

## Installation
1. In IP-Symcon die Kerninstanz **„Modules"** öffnen (Verwaltungskonsole →
   Kerninstanzen), unten rechts **+**, Repository-URL eintragen:
   `https://github.com/tomson9183/IPSymcon-Meross` – mit **OK** bestätigen und die
   Konsole neu verbinden. (Alternativ über den Module Store, sobald gelistet.)

## Konfiguration
1. Neue Instanz **„Meross Konfigurator"** anlegen.
2. Meross **E-Mail + Passwort** eintragen, **Region** wählen, **Übernehmen**.
3. **„Geräte aus der Cloud laden"** – holt Konto-Key, Geräteliste und sucht die
   lokalen IPs. (Nur hier erfolgt eine Cloud-Anmeldung; bei 2FA Code eintragen und
   erneut laden.)
4. Geräte mit **„Hinzufügen"** anlegen – Key und IP sind vorausgefüllt. Danach läuft
   die Steuerung **komplett lokal**.

**Geräte-Instanz** – Einstellungen: Lokale IP, Konto-Key (automatisch), Poll-Intervall
(Standard 3 s, 0 = aus) und **Kachel-Darstellung** (Automatisch / Hell / Dunkel).

### Wenn keine lokale IP gefunden wird
- Im Konfigurator das **Subnetz** eintragen (z. B. `192.168.178`) und „Lokale IPs
  erneut suchen".
- Oder dem Gerät im Router eine **feste IP** geben und sie im Gerät unter „Lokale IP
  des Geräts" eintragen.

## Visualisierung
Thermostat, Rollladen und Steckdose haben eine eigene **interaktive Kachel** (rundes
Thermostat-Dial mit Soll-Tippern/Modus, Rollladen-Fenster mit Auf/Stop/Zu + Slider,
Steckdose mit Schalter + Messwerten). Die Kacheln **skalieren mit der eingestellten
Kachelgröße** und passen sich **Hell-/Dunkel-Theme** an (umstellbar je Instanz).

### Hinweis Luftfeuchte (MTS215B / Matter)
Der **MTS215B** ist ein **Matter-Thermostat** und gibt die **Luftfeuchtigkeit nicht
über die lokale HTTP-Schnittstelle** heraus (nur über Matter/Cloud). Sie wird daher
bei diesem Modell lokal **nicht** angezeigt – Temperatur, Soll, Modus und Heizstatus
funktionieren normal. Modelle, die die Feuchte lokal liefern, zeigen sie automatisch.

## Hinweis Cloud-Sperre
Möglichst selten in der Cloud anmelden: Bei zu häufigen Anmeldungen sperrt Meross das
Konto vorübergehend (ca. 5 Stunden). Der Konfigurator meldet sich **nur per Knopfdruck**
an; die Geräte-Instanzen selbst **nie**.

## Fehlerbehebung
- **„Gerät lokal nicht erreichbar" (Status 201):** IP korrekt? Gerät im selben Netz?
  Feste IP vergeben.
- **„Kein Konto-Key / keine IP" (202):** Gerät über den Konfigurator anlegen oder Key/IP
  manuell eintragen.
- **Kachel leer / falsch skaliert:** Im Browser Visu neu laden; Modul auf die neueste
  Version aktualisieren; ggf. „Kachel-Darstellung" fest auf Hell/Dunkel stellen.

## Hinweise
- Inoffizielles Community-Modul, **keine Verbindung** zu Meross.
- Die genutzte Schnittstelle ist Community-dokumentiert; bitte die
  [Meross-Nutzungsbedingungen](https://www.meross.com/en-gc/terms-of-use) beachten.
  Nutzung **privat, nicht-kommerziell, auf eigenes Risiko**.
- Bereitstellung „wie besehen" ohne Gewähr (MIT) – besonders bei sicherheitsrelevanten
  Geräten (Garagentor, Heizung) eigenverantwortlich prüfen.
- Zugangsdaten und Konto-Key verbleiben in der eigenen IP-Symcon-Installation.

## Lizenz
MIT – siehe [LICENSE](LICENSE).

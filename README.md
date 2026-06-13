# IPSymcon-Meross

IP-Symcon-Modul zur **lokalen** Steuerung von Meross-Geräten über das LAN.
Die Meross-Cloud wird nur **einmalig** zum Auflisten der Geräte (und zum
automatischen Holen von Konto-Key und lokaler IP) genutzt – im Betrieb läuft
alles lokal: kein MQTT, kein Dauer-Cloud-Zwang, kein Python.

## Unterstützte Geräte & Dateien

Viele Meross-Modelle teilen sich denselben Steuer-Befehl. Daher ist je
**Steuer-Typ** eine Datei zuständig (im Ordner `MerossDevice/devices/`):

| Modelle | Typ | Datei | Status |
|---|---|---|---|
| MSS110, MSS210, MSS210P, MSS310, MSS310H, MSS420F, MSS425E, MSS510, MSS550, MSS570, MSS620, MSS710 | Steckdose / Schalter / Leiste (bis 6 Kanäle) | `PlugDevice.php` | MSS210P getestet |
| MSL100, MSL120, MSL320, MSL420, MSL430, MSS560 | Lampe / Dimmer / LED (An/Aus, Helligkeit, Farbe, Farbtemperatur) | `Light_MSL_MSS560.php` | dokumentiert, zu testen |
| MRS100 | Rollladen | `RollerDevice.php` | getestet |
| MSG100, MSG200 | Garagentoröffner | `Garage_MSG100_MSG200.php` | dokumentiert, zu testen |
| MSH300 (+ Unter-Geräte MS100, MS200, MTS100, MTS150) | Hub mit Sensoren & Thermostat-Ventilen | `Hub_MSH300.php` | dokumentiert, zu testen |
| MTS200, MTS200B, MTS215B, MTS960 | Eigenständiger WLAN-Thermostat | `Thermostat_MTS200_MTS215B_MTS960.php` | dokumentiert, zu testen |

Der **Konfigurator** erkennt den Typ automatisch anhand der Modellnummer und
legt die passende Instanz an. Unbekannte ToggleX-Geräte landen auf der
getesteten Steckdosen-Logik. „getestet" = an echter Hardware bestätigt;
„dokumentiert, zu testen" = nach offiziellem/Community-Protokoll gebaut, am
eigenen Gerät bitte prüfen.

## Installation (Kurzfassung)

1. In IP-Symcon: Kerninstanz **Modules** öffnen (unten rechts **+**), dieses
   GitHub-Repo hinzufügen, Konsole neu verbinden.
2. Instanz **„Meross Konfigurator"** anlegen, Meross E-Mail + Passwort
   eintragen, **„Übernehmen"**.
3. **„Geräte aus der Cloud laden"** – holt Konto-Key, Geräteliste und die
   lokalen IPs automatisch. (Nur dann erfolgt eine Cloud-Anmeldung.)
4. Geräte mit **„Hinzufügen"** anlegen – Key und IP sind vorausgefüllt.
   Fertig – die Steuerung läuft danach komplett lokal.

Eine ausführliche Schritt-für-Schritt-Anleitung liegt als PDF bei.

## Hinweis Cloud-Sperre
Möglichst selten in der Cloud anmelden. Bei zu häufigen Anmeldungen sperrt
Meross das Konto vorübergehend (ca. 5 Stunden). Der Konfigurator meldet sich
nur per Knopfdruck an, die Geräte-Instanzen selbst nie.

## Rechtliche Hinweise / Haftungsausschluss
- **Inoffiziell:** Dieses Projekt ist ein freies Community-Modul und steht in **keiner
  Verbindung** zu Meross bzw. der Chengdu Meross Technology Co., Ltd. und wird von
  dieser **nicht unterstützt oder geprüft**. „Meross" sowie Modellbezeichnungen sind
  Marken der jeweiligen Inhaber und werden hier nur zur **Beschreibung der
  Kompatibilität** genannt (nominative Nutzung).
- **Keine Gewähr / Haftung:** Die Nutzung erfolgt **auf eigene Gefahr**. Das Modul wird
  „wie besehen" ohne jede Gewährleistung bereitgestellt (siehe LICENSE/MIT). Eine
  Haftung für Schäden, Fehlfunktionen oder Datenverlust ist – soweit gesetzlich
  zulässig – ausgeschlossen. Das gilt besonders für **sicherheitsrelevante Geräte**
  (z. B. Garagentor, Heizung/Thermostat): Funktion und gefahrlose Bedienung sind
  eigenverantwortlich zu prüfen.
- **Lokale Schnittstelle:** Die Steuerung nutzt eine **inoffizielle, aus der
  Community dokumentierte Geräte-Schnittstelle**. Die einmalige Cloud-Anmeldung dient
  ausschließlich dem Abruf der **eigenen** Geräteliste, des Konto-Keys und der lokalen
  IPs mit den **eigenen Zugangsdaten** des Nutzers. Bitte die Nutzungsbedingungen von
  Meross beachten.
- **Daten:** Zugangsdaten und Konto-Key verbleiben in der eigenen IP-Symcon-Installation;
  das Modul gibt sie nicht an Dritte weiter.
- Diese Hinweise sind keine Rechtsberatung.

## Lizenz
MIT – siehe LICENSE.

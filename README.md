# IPSymcon-Meross

IP-Symcon-Modul zur **lokalen** Steuerung von Meross-Geräten über das LAN.
Die Meross-Cloud wird nur **einmalig** zum Auflisten der Geräte genutzt –
im Betrieb läuft alles lokal: kein MQTT, kein Dauer-Cloud-Zwang, kein Python.

## Stand: v1.0

Enthalten:
- **MSS210P** – Steckdose: Ein/Aus, Status-LED folgt automatisch dem Schaltzustand,
  und – falls das Gerät es kann – Leistung (W), Spannung (V), Strom (A) und
  Verbrauch (kWh).
- **Meross Geräte konfigurator**: einmal Meross-Zugangsdaten eintragen, alle
  Geräte des Kontos werden aufgelistet und per Klick als Instanz angelegt
  (Name, Typ und Konto-Key werden automatisch übernommen).

Weitere Geräte folgen Schritt für Schritt (z. B. MSS620-Doppelsteckdose,
MRS100-Rollladen). Wünsche bitte im Community-Thread nennen.

## Installation (Kurzfassung)

1. In IP-Symcon: Kerninstanz **Modules** öffnen, dieses GitHub-Repo hinzufügen,
   Konsole neu verbinden.
2. Instanz **„Meross Geräte konfigurator"** anlegen, Meross E-Mail + Passwort
   eintragen, „Übernehmen" – die Geräteliste erscheint.
3. Beim MSS210P auf **„Hinzufügen"** klicken.
4. In der angelegten Geräte-Instanz die **lokale IP** eintragen (Tipp: feste IP
   in der Fritzbox vergeben). Fertig – Schalten und Messwerte laufen lokal.

Eine ausführliche Schritt-für-Schritt-Anleitung liegt als PDF bei.

## Lizenz
MIT – siehe LICENSE.

# Prijsradar

Concurrentie-prijsmonitor voor de Lightspeed-shops van Paint Productions. PHP 7.4+, SQLite.

**Lees eerst het hoofdstuk bovenaan `radar.php`** (hoe het in elkaar zit, waar wat hoort, harde regels). Volledige technische documentatie: `DOCUMENTATIE.md`.

## Kort
- Deze repo = alleen code, identiek op elke site. Plesk haalt hem op in `data.<domein>/prijsradar/`.
- Per site, naast deze map, in `prijsradar-data/` (nooit in Git): `config.php` (sleutels, merk, site-regels) en `radar-<site>.sqlite`.
- Update: commit + push naar `main`, daarna per site in Plesk › Git › "Nu pull uitvoeren".
- Elke update: `PRIJSRADAR_VERSIE` in `radar.php` ophogen en bewijzen dat de andere sites identiek blijven.

## Sites
| Site | Data-subdomein |
|---|---|
| jotun-specialist.nl | data.jotun-specialist.nl |
| jotunfarbe.de | data.jotunfarbe.de |
| keim-specialist.nl | data.keim-specialist.nl (beheer: Edo) |

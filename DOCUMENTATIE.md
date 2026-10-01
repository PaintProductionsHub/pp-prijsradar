# Prijsradar: technische documentatie

Stand: 1 oktober 2026, versie `2026.10.01-24`.
Deze repo is openbaar: hier staan nooit sleutels, wachtwoorden, configs of databases. Die leven per site op de server.

---

## 1. Wat het is

Een concurrentie-prijsmonitor voor Lightspeed-webshops. Per site leest de radar:

1. **de eigen shop** via de Lightspeed API (volledige catalogus, inkoopprijzen, orders);
2. **de aanbieders** (concurrenten) via hun publieke website;
3. en zet per product, per inhoud en per kleurtype (of prijsgroep) de prijzen naast elkaar, met de **mediaan** als marktmaat.

Eén codebasis voor alle sites. Wat per site verschilt (merk, sleutels, regels) staat in de config van die site, niet in de code.

| Onderdeel | Techniek |
|---|---|
| Taal | PHP 7.4+ (getest op 7.4 en 8.4), geen frameworks, geen Composer |
| Opslag | SQLite (WAL-modus), één bestand per site |
| Server | Plesk (Apache achter nginx-proxy, ca. 60 s time-out per verzoek) |
| Uitrol | GitHub `PaintProductionsHub/pp-prijsradar`, tak `main`, Plesk Git |
| Login | HTTP Basic auth, wachtwoord uit de config (elke gebruikersnaam) |
| Taal interface | Nederlands, op alle sites |

---

## 2. Architectuur

### 2.1 Twee mappen per site

Op elk data-subdomein (`data.<shop-domein>`) staan naast elkaar:

```
prijsradar/              code, uit Git (deze repo). Een update vervangt deze map.
  index.php              weergave: tabs, acties, login, checks
  radar.php              motor: config, database, API, inventaris, matching-basis
  lezers.php             lezen van concurrent-websites (routes + productpagina's)
  grafieken.php          alle grafieken en overzichten
  omzet.php              best verkocht (orders) en marge (inkoopprijzen)
  .htaccess              verbergt .md en .gitignore voor de browser
  README.md, DOCUMENTATIE.md, .gitignore
prijsradar-data/         alles wat uniek is per site. Git en updates komen hier NOOIT.
  config.php             sleutels, wachtwoord, merk, site-regels
  radar-<site>.sqlite    database (+ -wal/-shm tijdens gebruik)
  .htaccess, index.html  afscherming (maakt de radar zelf aan)
```

### 2.2 Waar hoort wat

| Soort kennis | Plek | Wie wijzigt |
|---|---|---|
| Hoe de radar denkt (scannen, matchen, blokken, oordelen) | code (deze repo) | developer, via Git, voor alle sites tegelijk |
| Wat de radar over een markt weet (merk, productlijnen, blikmaten, jargon van concurrenten) | `prijsradar-data/config.php` | beheerder van die site |
| Wat een mens in de radar kiest (koppelingen, weergavenamen, minimale marge, volgorde) | database | via de schermen |

Vuistregel: ingesteld en zelden veranderd = config. Gekozen via een knop = database.

### 2.3 Harde regels

1. Geen site-specifieke waarden in de code: geen domeinen, merken, shopnamen of jargon van één concurrent.
2. In `config.php` staan alleen lijstjes en keuzes, nooit PHP-logica.
3. Een nieuwe functie staat standaard **uit** (config-sleutel), zodat andere sites identiek blijven.
4. Komt een site-regel op 2 of meer sites voor, dan wordt hij algemeen en verhuist hij naar de code.
5. Elke update: `PRIJSRADAR_VERSIE` ophogen en vóór uitrol bewijzen dat de andere sites identiek blijven.
6. Config en database gaan nooit mee in een update en worden nooit overschreven.
7. Nooit een kopie van de code per site (zoals ooit `prijsradarD1`). Twee radars op één database = oude code die in live data schrijft.

---

## 3. Uitrol en updates

### 3.1 Een update

1. Wijziging in deze repo, `PRIJSRADAR_VERSIE` in `radar.php` ophogen (`JJJJ.MM.DD-N`, N loopt door over dagen).
2. **Regressietest** (zie 9): oude en nieuwe code naast elkaar op een kopie van de database van elke site; alle tabs en alle productpagina's vergelijken. Alleen het versienummer en de bedoelde wijziging mogen verschillen.
3. Commit + push naar `main`.
4. Per site: Plesk › data.<domein> › Git › **Nu pull uitvoeren** (implementatiemodus Automatisch).
5. Controle per site: versie bovenaan de radar, en in tab <eigen shop> › Dekking: **Config** en **Database** met groen vinkje in `prijsradar-data/`.

### 3.2 Plesk Git per site (eenmalig)

| Veld | Waarde |
|---|---|
| Repository-URL | `https://github.com/PaintProductionsHub/pp-prijsradar.git` (openbaar, geen sleutel nodig) |
| Tak | `main` |
| Implementatiepad | de map `prijsradar` naast `prijsradar-data` (moet eerst als lege map bestaan) |
| Modus | Automatisch |

Waarom openbaar: Plesk maakt één SSH-sleutel per abonnement en GitHub staat één deploy key per repo toe; die sleutel hangt al aan de kleurenkiezer-repo. De code bevat geen geheimen, dus openbaar is de eenvoudigste route.

Let op: Plesk kiest alleen een map die al bestaat. Een verkeerd implementatiepad zet de code in de hoofdmap van het subdomein; Plesk ruimt die bestanden daarna niet zelf op.

### 3.3 Nieuwe site

1. Data-subdomein met PHP 7.4+ en SSL.
2. Map `prijsradar-data/` aanmaken, `config.php` erin (sjabloon: sectie 4).
3. Lege map `prijsradar/` en Plesk Git koppelen (3.2), pull.
4. In de radar: eigen shop ophalen → marge berekenen → best verkocht ophalen → aanbieders toevoegen (alleen homepage-URL) → minimale marge instellen in Dekking.

---

## 4. Config (`prijsradar-data/config.php`)

Een PHP-bestand dat een array teruggeeft. Zoekvolgorde in de code: `../prijsradar-data/config.php`, dan de oude plekken `config/config.php` en `./config.php` (alleen nog voor overgang; Dekking waarschuwt dan).

| Sleutel | Verplicht | Betekenis |
|---|---|---|
| `site` | ja | `www.domein.tld`. Bepaalt markt, btw en taal (uit de extensie). Ontbreekt hij, dan afgeleid van het subdomein. Plain tekst, nooit een markdown-link. |
| `merk` | ja | merknaam die de radar volgt (`Jotun`, `Keim`) |
| `merk_lijnen` | nee | productlijnen gescheiden door `|`, voor producten waarvan de naam het merk niet noemt (Keim: `soldalan|granital|...`) |
| `api` | ja | Lightspeed `key`, `secret` (taal en cluster zoekt de radar zelf; `/shop.json` controleert dat de sleutel bij deze shop hoort) |
| `wachtwoord` | ja | Basic-auth wachtwoord. `VERANDER-MIJ` of leeg = radar weigert (503) |
| `blikmaten` | nee | maten die als hetzelfde blik gelden, bv. Jotun `'2.7' => 3`. Leeg = elke maat is zichzelf |
| `vergelijk_variant` | nee | voorkeur wit/kleur zolang er geen omzetdata is |
| `omzet_vanaf` | nee | `JJJJ-MM-DD`: best verkocht telt pas vanaf deze datum (shop die eerder voor iets anders draaide) |
| `marge_voetnoot` | nee | eigen voetnoottekst bij marge |
| `drempels` | nee | zie 4.1 |
| `prijsgroepen` | nee | `true` = prijsgroep 0 t/m 4 als matchdimensie (zie 6.4). Standaard uit |
| `prijsgroep_regels` | nee | jargon van concurrenten voor prijsgroepen (zie 6.4) |
| `land`, `btw`, `http_taal` | nee | alleen bij afwijking van wat de extensie zegt |
| `tijdzone` | nee | standaard `Europe/Amsterdam` |
| `user_agent`, `pauze_sec` | nee | browser-identiteit en pauze tussen verzoeken bij het lezen van aanbieders |
| `maat_max` | nee | max. extra verzoeken voor Shopware-maatkeuze (standaard 400) |
| `standaardmaten`, `naam_definerend` | nee | fijnafstelling maten en productwoorden |
| `db` | **nooit gebruiken** | oude sleutel, veroorzaakte regressie v9-v11 |

### 4.1 Drempels (standaard)

| Sleutel | Standaard | Betekenis |
|---|---|---|
| `min_aanbieders` | 3 | minder aanbieders = "dunne markt" |
| `stunter_pct` | 10 | zoveel % onder de mediaan = stunter |
| `profiel_pct` | 5 | grens voor profiel per aanbieder (goedkoper/duurder) |
| `afwijker_pct` | 30 | zoveel % afwijking = koppeling controleren |
| `prijscheck` | 0.87 | per aanbieder: mediaan van (hun prijs ÷ eigen prijs) over vergelijkbare producten; daaronder = "erg laag", controleren (excl. btw? staffel? actie?) |
| `update_dagen` | 7 | ouder dan dit = automatische update bij de eerste pagina van een sessie |

### 4.2 Voorbeeld

```php
<?php
return [
    'site'        => 'www.voorbeeldshop.nl',
    'merk'        => 'Merknaam',
    'merk_lijnen' => 'lijn1|lijn2',
    'blikmaten'   => [],
    'api'         => ['key' => '...', 'secret' => '...'],
    'wachtwoord'  => '...',
    // 'prijsgroepen' => true,
    // 'prijsgroep_regels' => ['(?<![\p{L}\d])ms\s?1(?![\p{L}\d])' => 'pg3'],
];
```

---

## 5. Database

SQLite in `prijsradar-data/radar-<site>.sqlite` (site met punten als streepjes). `PRAGMA journal_mode=WAL`, `busy_timeout=15000`, `foreign_keys=ON`. Migraties gebeuren automatisch bij het openen (kolommen toevoegen, nooit verwijderen).

| Tabel | Inhoud |
|---|---|
| `bron` | aanbieders + eigen shop (`is_eigen=1`): naam, domein, platform, land, listing_url, actief |
| `run` | elke scan: start, eind, aantallen, log, dekking, methode, state (JSON stapmachine), slot, voortgang |
| `product` | product per bron: ext_id, titel, url, merk, zichtbaar, meenemen (NULL = automatisch), volgorde |
| `variant` | variant per product: ext_id, titel, ean, sku, inhoud_l, inhoud_std, kleurtype (bij scan; wordt bij tonen vers bepaald) |
| `meting` | prijs per variant per run: prijs, van_prijs, voorraad, levertijd, bron_veld, tijd. Basis voor trends |
| `instelling` | sleutel/waarde: `site` (bindt de DB aan één site), `min_marge`, `sortering`, API-basis |
| `keuze` | handmatige koppeling: eigen variant × aanbieder → hun variant (op variant-id) |
| `eigen_keuze` | welke eigen variant per product × blok vergeleken wordt |
| `naam` | eigen weergavenaam per product × blok |
| `verkoop_order`, `verkoop_regel`, `omzet_run`, `verkocht_indicatie` | orders uit Lightspeed voor best verkocht |
| `marge` | per variant: inkoop (priceCost), excl, incl |

**Veiligheid van de database**
- Eén database hoort bij één site (`instelling.site`). Een database van een andere site wordt geweigerd.
- Staan er meerdere kandidaten, dan wint de database van deze site met de meeste data; een lege wint nooit van een gevulde.
- Oude plekken (`prijsradar/data/`) worden één keer naar `prijsradar-data/` gekopieerd; het origineel blijft als `.gekopieerd`.
- Een database binnen de codemap geeft een waarschuwing in Dekking.

---

## 6. Hoe het werkt

### 6.1 Eigen shop (Lightspeed API)

- Volledige catalogus, ook niet-zichtbare producten. Taal en cluster worden automatisch gevonden (een taal die niet actief is geeft 404).
- **Marge** = `priceExcl` min `priceCost`, als % van de verkoopprijs excl. btw. Zonder inkoopprijs: "inkoop ontbreekt". Voetnoot: marge vóór verzend- en betaalkosten. Let op: sommige shops vullen priceCost met een formule (Keim: ca. 0,56 × prijs); dan is marge geen meting.
- **Best verkocht** = omzet per product uit orderregels, laatste 12 maanden (of sinds `omzet_vanaf`), geannuleerde orders en retouren niet meegeteld. Eerste vulling kan uren duren (stapsgewijs), daarna seconden.
- De vergeleken eigen variant per blok = de best verkochte.

### 6.2 Aanbieders lezen

**Toevoegen** met alleen de homepage-URL. De radar controleert of de site bereikbaar is, herkent het platform en zoekt de merkpagina of zoekresultaten.

| Platform | Herkenning | Route |
|---|---|---|
| Lightspeed | `webshopapp` | catalogus-JSON |
| WooCommerce | `woocommerce`, `data-product_variations` | variaties uit de pagina |
| Shopify | `cdn.shopify` | `/products.json` |
| Magento | `data-mage-init`, `Magento_` | pagina's |
| BigCommerce | `bigcommerce` | varianten-API met storefront-token |
| PrestaShop | `prestashop`, `static_token` | pagina's |
| Shopware 5/6, ePages, onbekend | rest | JSON-LD, microdata (`itemProp`, ook kleine letters), `og:price:amount` |
| Cloudflare-controle | `cf-mitigated`, `_cf_chl_opt` | **niet te lezen** (bv. verfmenger.com); de radar meldt het en voegt niets half toe |

**Volledigheid**: twee onafhankelijke routes (sitemap en platformcatalogus/zoekfunctie), daarna elke productpagina. Dekking per run wordt bewaard.

**Maat van een pagina** (als de maat niet in de naam staat), in deze volgorde:
1. gekozen optie in de maatkeuze;
2. "Inhalt" bij de hoofdprijs (niet bij cross-sell);
3. JSON-LD `referenceQuantity`;
4. Grundpreis: prijs ÷ €/L, afgerond op een standaardmaat (in DE wettelijk verplicht, dus vrijwel altijd aanwezig).

Shopware 5: overige maten via `?group[N]=optie`, één keer per productfamilie en prijsklasse, gemaximeerd door `maat_max`. Shopware 6: variant-URL's via het switch-endpoint, dubbele URL's eruit.

**Inhoud** (`inhoud()`): liters of kilo's, wat het eerst in de titel staat. Kilo's worden intern opgeslagen als 10000 + kg, zodat liters en kilo's nooit gekoppeld worden. `blikmaten` voegt maten samen.

**Prijs**: incl. btw. Shops die excl. btw tonen worden omgerekend met de btw van hun land (tabel `MARKTEN` in `radar.php`). Na elke scan vergelijkt `prijs_marktcontrole()` het prijsniveau van de aanbieder met de eigen shop (minstens 4 vergelijkbare producten); ligt de mediaan-verhouding onder `prijscheck`, dan krijgt de aanbieder het signaal "erg laag", zonder conclusie over de oorzaak.

### 6.3 Stapsgewijs scannen

nginx kapt verzoeken na ca. 60 s af. Daarom is elke scan een stapmachine (`run.state` als JSON): elke pagina-aanroep werkt ca. 20 s en geeft de voortgang terug, de browser vraagt de volgende stap. Een run die meer dan 2 uur open staat wordt "afgebroken (niet afgemaakt)". CLI: `php radar.php inventaris <bron-id|alle>` draait zonder tijdslimiet.

### 6.4 Vergelijken: het blok

Een **blok** = eigen product × inhoud × kleurtype. Sleutel `"0.75"` of `"0.75|kleur"` (`per_inhoud()`); een inhoud wordt pas gesplitst als er meerdere kleurtypes zijn.

**Kleurtype** (`kleurtype()`, vers bepaald bij het tonen via `kleur_nu()`): eerst uit de variantnaam, anders uit de productnaam (`kleurtype_naam()`, deel vóór het merk bij "Kleur | Merk Product"). Waarden: `wit`, `kleur`, `transparant`, `kleurloos`, `base a/b/c`. Kleurcodes van 4 of meer cijfers tellen als kleur.

**Prijsgroepen** (`prijsgroep()`, alleen met `'prijsgroepen' => true`): voor merken die per kleurgroep prijzen (KEIM: PG0 = wit t/m PG4). Elke prijsgroep wordt een eigen blok (`pg0`..`pg4`, label "PG3", "PG0 (wit)"). Volgorde van herkenning:
1. algemeen in de code: "Prijsgroep N", "Kleurgroep N", "Kleurgroep: N", "PG N" (N = 0..4); "Kleurgroep Wit" = pg0;
2. de `prijsgroep_regels` van de site, in volgorde, eerste treffer wint. Sleutel = regex (kleine letters, zonder scheidingstekens), waarde = `pg0`..`pg4`, `pg$1` (eerste groep) of `''` (= geen prijsgroep, stop). Een ongeldige regex wordt overgeslagen;
3. algemeen: wit, weiß, white, 100% wit = pg0.

Niets herkend = de gewone kleurtype-logica. Een eigen prijsgroep tegenover een concurrent zonder herkende prijsgroep wordt gekoppeld met een vraagteken.

### 6.5 Koppelen (matching)

Per blok zoekt de radar per aanbieder de best passende variant:

1. **Zelfde kleurtype** (of prijsgroep), of zonder aanduiding. Een andere kleurtype staat alleen onder "Andere kiezen".
2. **Zelfde inhoud** (na `blikmaten`).
3. **EAN/GTIN** gelijk = sterk signaal (in DE zelden aanwezig).
4. **Productwoorden** (`naam_woorden()`): merk, maat, kleur, kleurcodes (RAL/NCS/4-5 cijfers), winkeltekst, "3-in-1" en tekst na "ersetzt/ehemals/vervangt/nachfolger" vallen weg; "mix" en "f" zijn stopwoorden.
   - Gelijke woorden = automatisch.
   - Tikfout of alleen beschrijvende extra woorden = automatisch met vraagteken.
   - Een extra woord dat het product verandert (Matt, ME, Grob, pigmentiert, losse letter) = alleen voorstel, **maar alleen als de eigen shop dat woord zelf gebruikt** (`onze_woorden()`): een bijnaam als "Ultimate" onderscheidt dan niets.
5. Wederzijds beste match; gelijke stand = voorstel.
6. Veel gelijke kandidaten (bv. 200 RAL-pagina's): de **middelste prijs** van de topgroep, nooit de goedkoopste.

**Statusen**: "Automatisch gekoppeld, niet gecontroleerd" (grijs, knoppen "Klopt" / "Andere kiezen"); handmatig of bevestigd = "Gecontroleerd" (groen). Handmatige keuzes staan in `keuze` op variant-id; verdwijnt de variant van de concurrent bij een herscan, dan vervalt die koppeling.

### 6.6 Statistiek en oordelen

- Alleen de **mediaan**, per markt (land). Geen gemiddelde.
- Markt volgt uit de extensie: de eigen extensie = standaardmarkt; een aanbieder met een andere extensie voegt die markt toe; `.com/.eu` = eigen markt.
- Dunne markt: minder dan `min_aanbieders`.
- Marge-oordelen gaan uit van de **minimale marge die de site zelf instelt**; de radar oordeelt nooit zelf over "genoeg marge".
- Elk label of elke waarschuwing zegt waarom.

---

## 7. De interface

| Tab | Inhoud |
|---|---|
| **Overzicht** | kerncijfers (vergeleken, goedkoopst, boven mediaan, dunne markt), de markt in één beeld, "Aan de slag"-stappen |
| **Grafieken** | positie in de markt, marge × marktpositie, profiel per aanbieder, stunters, best verkocht, prijsladder, prijsadvies, drukte, prijsspreiding, prijswijzigingen, assortimentsgat, afwijkers, heatmap (achter een knop), prijsverloop (trends uit `meting`) |
| **Producten** | per product en blok: eigen prijs, laagste/mediaan/hoogste, per aanbieder de gekoppelde variant met status, "Andere kiezen", weergavenaam ✎ |
| **<eigen shop>** | eigen catalogus, in de radar aan/uit, volgorde; Dekking (gelezen via, config, database, best verkocht, marge, minimale marge) |
| **Aanbieders** | toevoegen (homepage-URL), bijwerken, alle aanbieders bijwerken, naam ✎, markt, verwijderen |

**Neutrale teksten**: nergens wij/ons/onze/we/jij (verzoek Norway Coatings); overal de naam van de referentieshop via `wn()`, label "referentie".

**Mobiel**: onder 700 px worden tabellen met 4+ kolomkoppen kaarten per regel (class `kt`, `data-l`-labels via JS); brede SVG-grafieken schuiven opzij met "Veeg opzij".

---

## 8. Beveiliging

- Radar: Basic auth (401 zonder login). `X-Robots-Tag: noindex`.
- `prijsradar-data/`: `.htaccess` `Require all denied` → config en database geven 403.
- Code-map: `.htaccess` verbergt `.md` en `.gitignore`.
- **Sitecheck**: past `site` in de config niet bij het subdomein, dan stopt de radar (500), zodat nooit de data of sleutel van een andere site gebruikt wordt.
- De API-sleutel wordt nergens getoond.
- Restrisico zit buiten de server: sleutels die in chats of Dropbox-kopieën staan. Advies: sleutels met alleen leesrechten, roteren na delen.
- Geen AI, geen agent, geen externe dienst: alleen de Lightspeed API en de websites van aanbieders (plus Google Fonts in de browser).

---

## 9. Testen (werkwijze)

- **Regressie**: per site een kopie van de live database (download via Plesk), oude en nieuwe code naast elkaar met `php -S`, alle tabs en alle productpagina's renderen en de zichtbare tekst vergelijken. Toegestaan verschil: het versienummer en de bedoelde wijziging.
- **Nieuwe functie achter een config-sleutel**: sleutel uit = identiek aan vorige versie bewijzen; sleutel aan = getest op de site waarvoor hij bedoeld is.
- **Config-regels**: elke titel uit de database door oude en nieuwe logica, 0 verschillen.
- Na uitrol live meten: versie, Config ✓, Database ✓, 0 PHP-fouten, aantallen gelijk.

---

## 10. CLI

```
php radar.php inventaris <bron-id|alle>      scan zonder tijdslimiet
php radar.php toevoegen "<naam>" <url>        aanbieder toevoegen
php radar.php verwijderen <bron-id>
php radar.php bronnen                         lijst
```

---

## 11. Geschiedenis en lessen

| Datum | Versie | Wat en waarom |
|---|---|---|
| 26-09 | t/m v8 | eerste versies op jotun-specialist.nl; alleen mediaan; marge, best verkocht |
| 27-09 | v9-v13 | **dataverlies** jotunfarbe: database stond in de codemap en de zip overschreef hem → data naar `prijsradar-data/`. **Regressie v9-v11**: oude config-sleutel `db` gaf een leeg beeld → nooit één kandidaat blind volgen, testen met de échte serverconfig |
| 27-09 | v14-v17 | jotunfarbe weinig matches: wit en kleur in één blok, maat niet gelezen bij Shopware/ePages, kleurcodes en Duitse winkeltekst als productwoord, bijnamen. Opgelost met blok per kleurtype, Grundpreis-lezer, `onze_woorden()`, middelste prijs |
| 28-09 | v18 | mobiel: tabellen als kaarten |
| 28-09 | v19-v20 | neutrale teksten (`wn()`); Keim live |
| 01-10 | v21 | config verhuist naar `prijsradar-data/` |
| 01-10 | v22-v23 | prijsgroepen (idee en regels: beheerder Keim) opgenomen; Keim-jargon van code naar config (`prijsgroep_regels`); architectuurregels bovenaan `radar.php` |
| 01-10 | v24 | uitrol via GitHub + Plesk Git op alle sites; Dekking toont welk config-bestand gelezen wordt |

**Lessen**
- Config of data nooit in een update. Sinds Git structureel onmogelijk.
- Een bestand terugdownloaden naar dezelfde map kan een nieuwe config overschrijven zonder dat iemand het ziet → Dekking toont nu het config-pad.
- Een config uit een chat kan een markdown-link bevatten; altijd plain tekst.
- Een config van een andere site neemt `merk_lijnen` en `blikmaten` mee; per merk opnieuw invullen uit de eigen catalogus.
- Nooit `prijsradar-data` tussen servers kopiëren.
- Lightspeed geeft 404 als de API-taal niet actief is in de shop.
- Duitse shops zetten winkeltekst in de productnaam; exacte woordmatching faalt daar zonder opschoning.
- EAN is in DE nutteloos als extra check: vrijwel geen shop toont hem.
- Cloudflare-beschermde shops zijn met deze aanpak niet te lezen; identieke prijzen bij zustershops dekken dat vaak af.

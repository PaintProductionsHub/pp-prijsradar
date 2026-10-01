<?php
// =====================================================================================================================
// PRIJSRADAR: HOE HET IN ELKAAR ZIT (lees dit eerst, mens of AI, vóór je iets wijzigt)
// =====================================================================================================================
// EEN SOFTWARE VOOR ALLE SITES. Deze code is op elke site identiek (jotun-specialist.nl, jotunfarbe.de,
// keim-specialist.nl, ...). Nooit een kopie per site maken (zoals ooit "prijsradarD1"): verbeteringen gaan in deze code,
// achter een instelling, zodat elke site ze kan aanzetten.
//
// TWEE MAPPEN PER SITE, NAAST ELKAAR OP HET DATA-SUBDOMEIN:
//   prijsradar/         = alleen code (deze 5 php-bestanden). Geen sleutels, geen wachtwoorden, geen site-kennis.
//                         Een update (zip of later Git) vervangt deze map blind.
//   prijsradar-data/    = alles wat uniek is per site. Een update komt hier NOOIT. Afgeschermd met .htaccess.
//       config.php          sleutels, wachtwoord, merk, productlijnen, blikmaten, drempels, site-regels (zie hieronder)
//       radar-<site>.sqlite  metingen + alles wat in de schermen gekozen is (handmatige koppelingen, namen, marge)
//
// WAAR HOORT IETS?
//   Hoe de radar denkt (scannen, matchen, blokken, oordelen)          -> code, voor alle sites tegelijk
//   Wat de radar over een markt moet weten (merk, woorden, regels)    -> prijsradar-data/config.php van die site
//   Wat een mens in de radar kiest (koppeling, naam, minimale marge)  -> database, via de schermen
//   Vuistregel: ingesteld en zelden veranderd = config; gekozen via een knop = database.
//
// HARDE REGELS
//   1. Geen site-specifieke waarden in de code: geen domeinen, merken, shopnamen of jargon van één concurrent.
//   2. In config.php staan alleen lijstjes en keuzes, nooit PHP-logica. Logica per site = stiekem meerdere softwares.
//   3. Een nieuwe functie staat standaard UIT (config-sleutel), zodat andere sites identiek blijven.
//   4. Komt een site-regel op 2+ sites voor, dan wordt hij algemeen en verhuist hij naar de code.
//   5. Elke update: versienummer hieronder ophogen en vóór uitrol bewijzen dat de andere sites identiek blijven
//      (zelfde pagina's, oude vs nieuwe code, op een kopie van hun database).
//   6. Database en config gaan nooit mee in een update en worden nooit overschreven.
//
// VOORBEELD SITE-REGELS: 'prijsgroep_regels' (alleen actief met 'prijsgroepen' => true), zie prijsgroep() verderop.
//   'prijsgroep_regels' => [ 'regex (kleine letters)' => 'pg0'..'pg4', '$1' = eerste groep, '' = geen prijsgroep ],
//   in volgorde gelezen, eerste treffer wint. Keim-voorbeeld: '(?<![\p{L}\d])ms\s?1(?![\p{L}\d])' => 'pg3' (Greenpaints).
// =====================================================================================================================
// Prijsradar: motor. Werkt voor elke webshop; alle site-specifieke instellingen staan in config.php.
// CLI:  php radar.php inventaris <bron-id|alle>
//       php radar.php toevoegen "<naam>" <listing-url>
//       php radar.php verwijderen <bron-id>
//       php radar.php bronnen

const PRIJSRADAR_VERSIE = '2026.10.01-24'; // ophogen bij elke update: zo zie je op elke site of de nieuwste code draait
// Instellingen per site in prijsradar-data/config.php, naast de database (een update raakt die map nooit). Oude plekken: config/config.php en ./config.php.
$_cfg = null; foreach ([dirname(__DIR__) . '/prijsradar-data/config.php', __DIR__ . '/config/config.php', __DIR__ . '/config.php'] as $_c) if (is_file($_c)) { $_cfg = $_c; break; }
if (!$_cfg) { if (PHP_SAPI !== 'cli') http_response_code(503); exit("Nog geen instellingen: zet config.php (vanuit config.voorbeeld.php) in de map prijsradar-data naast prijsradar en vul hem in voor deze site.\n"); }
$CFG = require $_cfg;
// config/ afschermen tegen bezoek via de browser (de map komt nooit mee in een update, dus de radar zorgt er zelf voor)
if (is_dir(__DIR__ . '/config') && !is_file(__DIR__ . '/config/.htaccess')) @file_put_contents(__DIR__ . '/config/.htaccess', "Require all denied\nDeny from all\n");
require_once __DIR__ . '/lezers.php';
require_once __DIR__ . '/omzet.php';
date_default_timezone_set($CFG['tijdzone'] ?? 'Europe/Amsterdam');
// Geen site-specifieke waarden in de code: alles komt uit config.php. Ontbreekt 'site', dan volgt die uit het subdomein (data.shop.nl → www.shop.nl).
if (empty($CFG['site']) && !empty($_SERVER['HTTP_HOST'])) { $CFG['site'] = 'www.' . preg_replace('/^(data|radar|prijsradar|www)\./i', '', strtolower($_SERVER['HTTP_HOST'])); $CFG['_site_afgeleid'] = true; } // afgeleid = niet vastleggen in de database
// ---------- markten ----------
// De markt volgt uit de domeinextensie: onze shop bepaalt de standaardmarkt, elke aanbieder zijn eigen markt.
// Een aanbieder uit een ander land (.de, .be, ...) maakt vanzelf een extra markt in de keuzelijst. .com/.eu e.d. = onze markt.
const MARKTEN = ['NL' => [21, 'nl-NL,nl;q=0.9'], 'BE' => [21, 'nl-BE,nl;q=0.9,fr;q=0.8'], 'DE' => [19, 'de-DE,de;q=0.9'], 'AT' => [20, 'de-AT,de;q=0.9'],
    'CH' => [8.1, 'de-CH,de;q=0.9'], 'LU' => [17, 'fr-LU,fr;q=0.9,de;q=0.8'], 'FR' => [20, 'fr-FR,fr;q=0.9'], 'DK' => [25, 'da-DK,da;q=0.9'],
    'SE' => [25, 'sv-SE,sv;q=0.9'], 'NO' => [25, 'nb-NO,nb;q=0.9'], 'GB' => [20, 'en-GB,en;q=0.9'], 'ES' => [21, 'es-ES,es;q=0.9'], 'IT' => [22, 'it-IT,it;q=0.9'], 'PL' => [23, 'pl-PL,pl;q=0.9']];
function land_van(string $host): ?string {
    $tld = strtoupper((string)preg_replace('/^.*\./', '', preg_replace('#^(https?://)?([^/:]+).*$#', '$2', strtolower($host))));
    $tld = $tld === 'UK' ? 'GB' : $tld;
    return isset(MARKTEN[$tld]) ? $tld : null;
}
$CFG['land'] = $CFG['land'] ?? (land_van((string)($CFG['site'] ?? '')) ?? 'NL');   // onze markt: uit het domein van onze shop (config 'land' mag overschrijven)
$CFG['http_taal'] = $CFG['http_taal'] ?? MARKTEN[$CFG['land']][1] ?? 'nl-NL,nl;q=0.9';
// Btw per markt; config 'btw' overschrijft alleen die van onze eigen markt.
function btw_van(string $land): float { return $land === $GLOBALS['CFG']['land'] && isset($GLOBALS['CFG']['btw']) ? (float)$GLOBALS['CFG']['btw'] : (float)(MARKTEN[$land][0] ?? 21); }
function btw_eigen(): float { return btw_van($GLOBALS['CFG']['land']); }
// Btw van de aanbieder die nu gescand wordt (voor shops die excl. btw tonen)
function btw_bron(): float { return btw_van($GLOBALS['SCAN_LAND'] ?? $GLOBALS['CFG']['land']); }
// Markten in de keuzelijst: onze markt eerst, daarna elke markt waar een aanbieder zit.
function markten(): array { return array_values(array_unique(array_merge([$GLOBALS['CFG']['land']], db()->query('SELECT DISTINCT land FROM bron WHERE is_eigen=0 AND land IS NOT NULL ORDER BY land')->fetchAll(PDO::FETCH_COLUMN)))); }
// Bedrijfskeuzes per site, met standaardwaarden. Aanpasbaar in config.php onder 'drempels'.
function drempel(string $k) {
    $std = ['min_aanbieders' => 3, 'stunter_pct' => 10, 'profiel_pct' => 5, 'afwijker_pct' => 30, 'prijscheck' => 0.87, 'update_dagen' => 7];
    return $GLOBALS['CFG']['drempels'][$k] ?? $std[$k];
}
function site_host(): string { return preg_replace('#^(https?://)?(www\.)?|/.*$#', '', strtolower((string)($GLOBALS['CFG']['site'] ?? ''))); }
function instelling($k, $v = null) {
    if ($v !== null) { db()->prepare('INSERT OR REPLACE INTO instelling VALUES(?,?)')->execute([$k, $v]); return $v; }
    $q = db()->prepare('SELECT waarde FROM instelling WHERE sleutel=?'); $q->execute([$k]); $r = $q->fetchColumn(); return $r === false ? null : $r;
}

// Waar staat de database? Altijd op één vaste plek: de map prijsradar-data direct naast de map prijsradar
// (radar-<site>.sqlite, afgeschermd met .htaccess). Een update raakt alleen de map prijsradar, nooit prijsradar-data. Staat de data nog op een oude plek, dan wordt hij één keer hierheen gekopieerd;
// het origineel blijft staan (hernoemd naar .gekopieerd), er wordt nooit iets verwijderd.
function db_plekken(): array {
    $d = []; $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($root !== '') { $d[] = dirname($root) . '/prijsradar-data'; $d[] = $root . '/prijsradar-data'; }
    foreach ([dirname(__DIR__, 1), dirname(__DIR__, 2), dirname(__DIR__, 3), dirname(__DIR__, 4)] as $b) $d[] = $b . '/prijsradar-data';
    return array_values(array_unique($d));
}
function db_site(string $f) { try { $x = new PDO('sqlite:' . $f); $s = @$x->query("SELECT waarde FROM instelling WHERE sleutel='site'"); return $s ? $s->fetchColumn() : false; } catch (Throwable $e) { return false; } }
function db_omvang(string $f): int {
    try { $x = new PDO('sqlite:' . $f); $q = @$x->query('SELECT (SELECT COUNT(*) FROM bron) + (SELECT COUNT(*) FROM product)'); $n = $q ? (int)$q->fetchColumn() : 0; $x = null; return $n; } catch (Throwable $e) { return 0; }
}
function db_pad(): string {
    static $pad = null; if ($pad !== null) return $pad;
    $map = dirname(__DIR__) . '/prijsradar-data'; @mkdir($map, 0775, true);
    if (!is_file("$map/.htaccess")) @file_put_contents("$map/.htaccess", "Require all denied\nDeny from all\n");
    if (!is_file("$map/index.html")) @file_put_contents("$map/index.html", '');
    $doel = $map . '/radar-' . (preg_replace('/[^a-z0-9]+/', '-', site_host()) ?: 'site') . '.sqlite';
    // alle bestaande databases van deze site (vaste plek, oude plekken, oude config-regel 'db'); de meeste data wint
    $kand = [$doel];
    if (!empty($GLOBALS['CFG']['db']) && is_string($GLOBALS['CFG']['db'])) $kand[] = $GLOBALS['CFG']['db'];
    foreach (db_plekken() as $d) foreach ((array)@glob($d . '/*.sqlite') as $f) $kand[] = $f;
    foreach ((array)@glob($map . '/*.sqlite') as $f) $kand[] = $f;
    foreach ((array)@glob(__DIR__ . '/data/*.sqlite') as $f) $kand[] = $f;  // oude noodplek
    $best = null; $bestN = -1;
    foreach (array_unique($kand) as $f) if (is_file($f) && filesize($f) > 0) {
        $van = db_site($f); if ($van !== false && preg_replace('/^www\./', '', (string)$van) !== site_host()) continue;
        $n = db_omvang($f); if ($n > $bestN || ($n === $bestN && $f === $doel)) { $best = $f; $bestN = $n; }
    }
    if ($best === null || $best === $doel) return $pad = $doel;
    // data staat op een andere plek: kopiëren naar de vaste plek, controleren, origineel laten staan als .gekopieerd
    try { $x = new PDO('sqlite:' . $best); $x->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $x = null; } catch (Throwable $e) { return $pad = $best; }
    if (is_file($doel)) @rename($doel, $doel . '.' . date('YmdHis') . '.leeg');
    if (@copy($best, $doel) && filesize($doel) === filesize($best) && db_omvang($doel) === $bestN) { @rename($best, $best . '.gekopieerd'); return $pad = $doel; }
    @unlink($doel); return $pad = $best; // kopiëren lukte niet: gewoon op de oude plek verder werken
}
// Alle prijsradar-databases die de radar kan zien (voor de controle in Onze shop): pad, site, aantal aanbieders, grootte.
function db_overzicht(): array {
    $uit = []; $dirs = array_merge(db_plekken(), [__DIR__ . '/data']);
    foreach ($dirs as $d) foreach ((array)@glob($d . '/*.sqlite') as $f) {
        $n = null; try { $x = new PDO('sqlite:' . $f); $q = @$x->query('SELECT COUNT(*) FROM bron WHERE is_eigen=0'); $n = $q ? (int)$q->fetchColumn() : null; $x = null; } catch (Throwable $e) {}
        $uit[] = ['pad' => $f, 'site' => db_site($f) ?: '(onbekend)', 'aanbieders' => $n, 'kb' => (int)round(@filesize($f) / 1024), 'actief' => realpath($f) === realpath(db_pad())];
    }
    return $uit;
}
function db(): PDO {
    global $CFG; static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . db_pad());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout=15000'); $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA synchronous=NORMAL'); $pdo->exec('PRAGMA foreign_keys=ON'); // WAL: lezen blokkeert schrijven niet (pagina blijft snel tijdens een scan) $pdo->setAttribute(PDO::ATTR_TIMEOUT, 15);
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS bron (id INTEGER PRIMARY KEY, naam TEXT, domein TEXT, platform TEXT, land TEXT,
        listing_url TEXT, is_eigen INTEGER DEFAULT 0, actief INTEGER DEFAULT 1, toegevoegd TEXT);
    CREATE TABLE IF NOT EXISTS run (id INTEGER PRIMARY KEY, bron_id INTEGER REFERENCES bron(id) ON DELETE CASCADE,
        start TEXT, eind TEXT, producten INTEGER, varianten INTEGER, fouten INTEGER, log TEXT);
    CREATE TABLE IF NOT EXISTS product (id INTEGER PRIMARY KEY, bron_id INTEGER REFERENCES bron(id) ON DELETE CASCADE,
        ext_id TEXT, titel TEXT, url TEXT, merk TEXT, eerst_gezien TEXT, laatst_gezien TEXT, UNIQUE(bron_id, ext_id));
    CREATE TABLE IF NOT EXISTS variant (id INTEGER PRIMARY KEY, product_id INTEGER REFERENCES product(id) ON DELETE CASCADE,
        ext_id TEXT, titel TEXT, ean TEXT, sku TEXT, inhoud_l REAL, inhoud_std REAL, kleurtype TEXT,
        eerst_gezien TEXT, laatst_gezien TEXT, UNIQUE(product_id, ext_id));
    CREATE TABLE IF NOT EXISTS meting (id INTEGER PRIMARY KEY, variant_id INTEGER REFERENCES variant(id) ON DELETE CASCADE,
        run_id INTEGER REFERENCES run(id) ON DELETE CASCADE, prijs REAL, van_prijs REAL, op_voorraad INTEGER,
        levertijd TEXT, bron_veld TEXT, tijd TEXT);
    ");
    // kleine migraties (bestaande database blijft werken)
    $kol = function ($t) use ($pdo) { return array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name'); };
    if (!in_array('dekking', $kol('run'), true)) $pdo->exec('ALTER TABLE run ADD COLUMN dekking TEXT');
    if (!in_array('methode', $kol('run'), true)) $pdo->exec('ALTER TABLE run ADD COLUMN methode TEXT');
    if (!in_array('zichtbaar', $kol('product'), true)) $pdo->exec('ALTER TABLE product ADD COLUMN zichtbaar INTEGER DEFAULT 1');
    if (!in_array('meenemen', $kol('product'), true)) $pdo->exec('ALTER TABLE product ADD COLUMN meenemen INTEGER');   // NULL = automatisch
    if (!in_array('volgorde', $kol('product'), true)) $pdo->exec('ALTER TABLE product ADD COLUMN volgorde INTEGER');
    if (!in_array('state', $kol('run'), true)) $pdo->exec('ALTER TABLE run ADD COLUMN state TEXT');
    if (!in_array('slot', $kol('run'), true)) $pdo->exec('ALTER TABLE run ADD COLUMN slot INTEGER');
    if (!in_array('voortgang', $kol('run'), true)) $pdo->exec('ALTER TABLE run ADD COLUMN voortgang TEXT');
    // Eén database hoort bij één site: bij een andere site weigeren we, zodat nooit data van een andere site getoond wordt.
    $pdo->exec('CREATE TABLE IF NOT EXISTS instelling (sleutel TEXT PRIMARY KEY, waarde TEXT)');
    $van = $pdo->query("SELECT waarde FROM instelling WHERE sleutel='site'")->fetchColumn();
    if ($van === false && site_host() !== '' && empty($CFG['_site_afgeleid'])) { $pdo->prepare("INSERT OR IGNORE INTO instelling VALUES('site',?)")->execute([site_host()]); $van = $pdo->query("SELECT waarde FROM instelling WHERE sleutel='site'")->fetchColumn(); }
    if ($van !== false && site_host() !== '' && preg_replace('/^www\./', '', $van) !== site_host()) { $pdo = null; throw new RuntimeException("Deze database hoort bij $van, niet bij " . site_host() . '. Controleer het db-pad in config.php.'); }
    return $pdo;
}

function nu(): string { return date('Y-m-d H:i:s'); }
// Harde eindtijd per stap: nginx kapt na ~60 s af, dus een stap (budget + laatste verzoek) blijft onder ~45 s.
function stap_rest(): float { return isset($GLOBALS['STAP_EIND']) ? $GLOBALS['STAP_EIND'] - microtime(true) : 45.0; }
function stap_timeout(int $max): int { return (int)max(4, min($max, floor(stap_rest()))); }

// Domeinen met ö/ü/ä (bv. farbenkönig.de) in de ASCII-vorm (xn--...), zoals sitemaps en servers ze gebruiken.
function host_ascii(string $h): string { $h = strtolower($h); return (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $h) && ($x = idn_to_ascii($h, 0, INTL_IDNA_VARIANT_UTS46))) ? $x : $h; }
function url_ascii(string $u): string { $h = parse_url($u, PHP_URL_HOST); return $h ? str_replace($h, host_ascii($h), $u) : $u; }
function haal(string $url, ?int &$code = null): ?string {
    global $CFG;
    $ch = curl_init(url_ascii($url));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => stap_timeout(20),
        CURLOPT_USERAGENT => $CFG['user_agent'], CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept-Language: ' . $CFG['http_taal'], 'Accept: text/html,application/json;q=0.9,*/*;q=0.8']]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    usleep((int)($CFG['pauze_sec'] * 1e6));
    return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
}

// ---------- platform herkennen ----------
// Platforms die de algemene scan (sitemap + platformcatalogus + productpagina's) leest; Lightspeed heeft een eigen route.
const GEN_PLATFORMS = ['woocommerce', 'shopify', 'magento', 'bigcommerce', 'prestashop', 'onbekend'];
function herken_platform(string $html): string {
    if (stripos($html, 'cf-mitigated') !== false || stripos($html, '_cf_chl_opt') !== false) return 'geblokkeerd (Cloudflare)';
    if (stripos($html, 'webshopapp') !== false) return 'lightspeed';
    if (stripos($html, 'data-product_variations') !== false || stripos($html, 'woocommerce') !== false) return 'woocommerce';
    if (stripos($html, 'cdn.shopify') !== false) return 'shopify';
    if (stripos($html, 'data-mage-init') !== false || stripos($html, 'Magento_') !== false || stripos($html, 'Mage.Cookies') !== false) return 'magento';
    if (stripos($html, 'bigcommerce') !== false) return 'bigcommerce';
    if (stripos($html, 'prestashop') !== false || preg_match('/var (baseDir|static_token|prestashop)\b/', $html)) return 'prestashop';
    return 'onbekend';
}

// ---------- variant-kenmerken uit de titel ----------
// Inhoud: liters of kilo's, wat het eerst in de titel staat ("25 kg (ca. 16 l)" = 25 kg). Kilo's als 10000 + kg, zodat liters en kilo's nooit gekoppeld worden.
// 'blikmaten' in config.php: maten die als hetzelfde blik gelden (bv. Jotun 2,7 l = 3 l). Standaard geen.
function inhoud(string $t): array {
    $l = preg_match('/(\d+(?:[.,]\d+)?)\s*(ml|ltr|liters?|litre|lt|l)\b/iu', $t, $ml, PREG_OFFSET_CAPTURE);
    $g = preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|kilo|gram|gr|g)\b/iu', $t, $mg, PREG_OFFSET_CAPTURE);
    if (!$l && !$g) return [null, null];
    if ($g && (!$l || $mg[0][1] < $ml[0][1])) {
        $kg = (float)str_replace(',', '.', $mg[1][0]); if (!in_array(strtolower($mg[2][0]), ['kg', 'kilo'], true)) $kg /= 1000;
        return [$kg, 10000 + round($kg, 3)];
    }
    $v = (float)str_replace(',', '.', $ml[1][0]); if (strtolower($ml[2][0]) === 'ml') $v /= 1000;
    $k = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return [$v, (float)($GLOBALS['CFG']['blikmaten'][$k] ?? $v)];
}
// ---------- prijsgroepen (aan met config 'prijsgroepen' => true; idee en regels: Edo, keim-specialist, 28-09-2026) ----------
// Sommige merken (KEIM) prijzen per kleur-/prijsgroep: 0 = wit t/m 4 = de duurste tint. Elke prijsgroep is dan een eigen
// markt: hun "Kleurgroep 3" hoort naast onze "Prijsgroep 3", niet naast hun wit. De code kent alleen de ALGEMENE taal;
// hoe een concurrent zijn groepen noemt (MS1, S-9009, "7 / 2,5 kg", ...) is kennis van die markt en staat in
// 'prijsgroep_regels' in prijsradar-data/config.php van die site (zie het hoofdstuk bovenaan dit bestand).
// Volgorde: 1. algemeen "Prijsgroep/Kleurgroep N", "PG N", "Kleurgroep Wit"  2. regels van deze site, in volgorde
// 3. algemeen wit/weiß/white. Een regel met uitkomst '' = "dit is geen prijsgroep" (stopt, gewone kleurtype-logica volgt).
// Staat 'prijsgroepen' uit (standaard), dan verandert er niets.
function prijsgroepen_aan(): bool { return !empty($GLOBALS['CFG']['prijsgroepen']); }
function prijsgroep(string $t): string {
    if (preg_match('/(?:prijsgroep|kleurgroep)\s*:?\s*([0-4])(?!\d)/u', $t, $m) || preg_match('/(?<![\p{L}])pg\s*([0-4])(?!\d)/u', $t, $m)) return 'pg' . $m[1];
    if (preg_match('/kleurgroep\s*:?\s*(wit|weiss|weiß)(?![\p{L}])/u', $t)) return 'pg0';
    foreach ((array)($GLOBALS['CFG']['prijsgroep_regels'] ?? []) as $patroon => $groep) {
        $r = @preg_match('~' . $patroon . '~u', $t, $m);
        if ($r === false) continue; // fout patroon in de config: overslaan, niet crashen
        if ($r) return str_replace('$1', $m[1] ?? '', (string)$groep);
    }
    if (preg_match('/(?<![\p{L}])(100%\s*wit|wit|weiß|weiss|white)(?![\p{L}])/u', $t)) return 'pg0';
    return '';
}
// Leesbaar label voor een kleurtype in de kop van een blok: "PG3", "PG0 (wit)", anders het type zelf ("kleur").
function kleurtype_label(string $k): string { return $k === 'pg0' ? 'PG0 (wit)' : (preg_match('/^pg([0-4])$/', $k, $m) ? 'PG' . $m[1] : $k); }

// Kleurtype van een variant (NL, DE, EN), op hele woorden: wit / kleur / transparant / kleurloos / base a-c.
// Met 'prijsgroepen' aan eerst de prijsgroep (pg0-pg4); pas als die niet herkend wordt de gewone typen.
function kleurtype(string $t): string {
    $t = mb_strtolower($t);
    if (prijsgroepen_aan() && ($pg = prijsgroep($t)) !== '') return $pg;
    foreach (['base a' => 'base a|basis a|a-base', 'base b' => 'base b|basis b|b-base', 'base c' => 'base c|basis c|c-base',
              'transparant' => 'transparante?|transparent', 'kleurloos' => 'kleurloos|farblos|colou?rless',
              'kleur' => 'kleur|mengkleur|mix|mischfarbe|anders|sonderfarbe|nach wahl|colou?r|getönt|farbton|tönung|getint|tinted|wunschfarbe|wunschfarbton|wunschton|farbig|ral', 'wit' => 'wit|weiß|weiss|white|hvit'] as $type => $w) // kleur vóór wit: "weiß getönt" = kleur
        if (preg_match('/(?<![\p{L}])(' . $w . ')(?![\p{L}])/u', $t)) return $type;
    // kleurcode met naam: "10462 - livlig", "1973 ANTIKKGRÅ" (geen maat: "750 ml", "2500 gramm" tellen niet)
    return preg_match('/(?<![\d,.])\d{3,5}\s*-?\s*(?!(?:ml|ltr|liter|litre|gramm|gram|kilo|stück|stuck|stuks|st)(?!\p{L}))\p{L}{3,}/u', $t) ? 'kleur' : '';
}

// ---------- productnamen vergelijken ----------
// Een naam wordt teruggebracht tot de woorden die het product bepalen: zonder merk, inhoud, kleur en algemene woorden.
// "Jotun Demidekk Ultimate 2,7 Liter weiß" en "Demidekk Ultimate" worden allebei {demidekk, ultimate}.
function naam_woorden(string $t): array {
    static $cache = [];
    if (isset($cache[$t])) return $cache[$t];
    $t = naam_zonder_kleurdeel($t);
    // "Naam | ondertitel": het deel met het merk of een productlijn (shops zetten er soms eerst de kleur voor: "RAL 7016 | Jotun Demidekk Cleantech")
    $delen = explode('|', $t); $s = $delen[0];
    if (count($delen) > 1) { $re = '/(?<![a-z0-9])(' . implode('|', array_filter(array_merge([preg_quote(mb_strtolower(merk()), '/')], explode('|', mb_strtolower((string)($GLOBALS['CFG']['merk_lijnen'] ?? '')))))) . ')/u';
        foreach ($delen as $d) if (preg_match($re, mb_strtolower($d))) { $s = $d; break; } }
    $s = mb_strtolower(trim($s));
    $s = str_replace(['ß', 'ä', 'ö', 'ü', 'é', 'è', 'ë'], ['ss', 'a', 'o', 'u', 'e', 'e', 'e'], $s);
    // tekst over een voorganger telt niet: "Cleantech - ersetzt Demidekk Optimal & Ultimate" is Cleantech
    $s = preg_replace('/(?<![a-z])(ersetzt|ehemals|vormals|fruher|nachfolger|vervangt|vervangen door|voorheen|opvolger van|replaces|formerly)(?![a-z]).*$/u', ' ', $s);
    $s = preg_replace('/\d\s*-?\s*(?:in|i)\s*-?\s*\d/u', ' ', $s); // "3-in-1", "3-i-1"
    if (merk() !== '') $s = preg_replace('/(?<![a-z0-9])' . preg_quote(mb_strtolower(merk()), '/') . '(?![a-z0-9])/u', ' ', $s);
    $s = preg_replace('/\d+(?:[.,]\d+)?\s*(?:x\s*)?(?:ml|ltr|liters?|litre|lt|l|kg|kilo|gram|gr|g)(?![a-z])/u', ' ', $s);
    $stop = array_flip(explode(' ', 'verf lak lakverf gevelverf muurverf buitenverf binnenverf silicaatverf mineraalverf kalkverf houtverf betonverf vloerverf '
        . 'farbe fassadenfarbe wandfarbe innenfarbe aussenfarbe holzfarbe silikatfarbe lack paint '
        . 'wit white weiss kleur kleuren farbig getont farbton wunschfarbe wunschfarbton wunschton color colour ral basis base transparant transparent kleurloos farblos klar '
        . 'liter ltr litre kilo emmer blik eimer dose gebinde stuk stuks st set nieuw neu new mix f '
        . 'de het een en van voor met der die das und fur mit the and for of in og ol oel'));
    $w = [];
    foreach (preg_split('/[^a-z0-9åøæ]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $x) if (!isset($stop[$x])) $w[$x] = 1; // ook losse letters: Lotexan-N ≠ Lotexan
    $w = array_map('strval', array_keys($w)); sort($w, SORT_STRING);
    return $cache[$t] = $w;
}
// "EGGHVIT | Jotun Demidekk Cleantech | Holzfarbe": staat er vóór het merkdeel nog een deel, dan is dat de kleur.
function merkdeel_index(array $delen): int {
    $re = '/(?<![a-z0-9])(' . implode('|', array_filter(array_merge([preg_quote(mb_strtolower(merk()), '/')], explode('|', mb_strtolower((string)($GLOBALS['CFG']['merk_lijnen'] ?? '')))))) . ')/u';
    foreach ($delen as $i => $d) if (preg_match($re, mb_strtolower($d))) return $i;
    return 0;
}
function naam_zonder_kleurdeel(string $t): string { $d = explode('|', $t); $i = count($d) > 1 ? merkdeel_index($d) : 0; return implode('|', array_slice($d, $i)); }
// Kleurtype uit een productnaam: een kleurdeel vóór het merk ("DEMIVIT | Jotun ...") is een kleur, ook zonder kleurwoord; "Weiss | Jotun ..." blijft wit.
function kleurtype_naam(string $t): string {
    $d = explode('|', $t); $i = count($d) > 1 ? merkdeel_index($d) : 0;
    if ($i > 0) { $k = kleurtype(implode(' ', array_slice($d, 0, $i))); return $k !== '' ? $k : 'kleur'; }
    return kleurtype(trim($d[0]));
}
function naamsleutel(string $t): string { return implode(' ', naam_woorden($t)); }
// Hoe goed lijken twee namen op elkaar? 3 = dezelfde woorden, 2 = dezelfde woorden op een tikfout of los nummer na,
// 1 = de ene naam heeft extra woorden (bv. Soldalit vs Soldalit-ME: mogelijk een ander product, nooit automatisch), 0 = anders.
function naam_score(array $a, array $b): int {
    if (!$a || !$b) return 0;
    if ($a === $b) return 3;
    [$kort, $lang] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
    $extra = naam_rest($kort, $lang);                        // null = de kortste naam zit niet (op tikfouten na) in de langste
    if ($extra !== null) {
        if (!$extra) return 2;                               // dezelfde woorden, op een tikfout na
        $cijfer = array_filter($extra, 'ctype_digit');
        if (count($cijfer) === count($extra) && !array_filter($kort, 'ctype_digit')) return 2; // "Primadekk 02" vs "Primadekk"
        if (!array_filter($extra, function ($w) { return !naam_beschrijvend($w); })) return 2; // alleen beschrijvende woorden extra ("Holzöl", "deckende")
        if (max(array_map('strlen', $kort)) >= 4) return 1;  // extra woord dat het product kan veranderen (Soldalit-ME, Matt): alleen voorstel
    }
    return 0;
}
// Zit elk woord van $kort (of een tikfout ervan) in $lang? Geeft de overgebleven woorden van $lang, of null.
function naam_rest(array $kort, array $lang): ?array {
    foreach ($kort as $x) { $hit = null;
        foreach ($lang as $i => $y) if ($x === $y || woord_tikfout($x, $y)) { $hit = $i; break; }
        if ($hit === null) return null; unset($lang[$hit]); }
    return array_values($lang);
}
// Een extra woord in een productnaam is beschrijvend (winkeltekst als "Holzöl", "Fensterfarbe") als het geen code of nummer is,
// geen productlijn van het merk, en geen woord dat een ander product aanduidt (glansgraad, variant, grof/fijn, ...).
function naam_beschrijvend(string $w): bool {
    static $def = null, $lijn = null;
    if ($def === null) {
        $def = array_flip(array_merge(explode(' ', 'matt mat halbmatt seidenmatt glanz glans halbglanz seidenglanz halvblank blank gloss silk satin zijdemat zijdeglans hoogglans '
            . 'pigmentiert pigmentert ultimate infinity optimal plus pro extra premium aqua solvent waterbasis wasserbasiert grob fein grof fijn'),
            array_filter(explode(' ', (string)($GLOBALS['CFG']['naam_definerend'] ?? '')))));
        $lijn = array_flip(array_map(function ($x) { return trim($x, '-'); }, explode('|', strtolower((string)($GLOBALS['CFG']['merk_lijnen'] ?? '')))));
    }
    if (ctype_digit($w)) return strlen($w) >= 4;              // kleurcode (RAL 1000, NCS, Jotun 10462) = winkeltekst, kort getal = variant
    if (strlen($w) < 4) return false;
    if (!isset($def[$w]) && !isset($lijn[$w])) return true;
    // Een productwoord (lijn of glansgraad) onderscheidt alleen iets als wíj het ook gebruiken. Wij verkopen geen "Ultimate" of
    // "Oljetäckfärg": in "Cleantech - Ultimate Täckfärg" en "Oljedekkbeis / Oljetäckfärg" is het de oude of Zweedse naam van hetzelfde blik.
    $o = onze_woorden(); return $o !== null && !isset($o[$w]);
}
// Alle productwoorden van onze eigen shop (null = onbekend, dan wordt er niets versoepeld)
function onze_woorden(): ?array {
    static $w = false;
    if ($w === false) { $w = [];
        try { foreach (db()->query('SELECT DISTINCT p.titel FROM product p JOIN bron b ON b.id=p.bron_id WHERE b.is_eigen=1')->fetchAll() as $r) foreach (naam_woorden((string)$r['titel']) as $x) $w[$x] = 1; }
        catch (Throwable $e) { $w = []; }
        if (!$w) $w = null; }
    return $w;
}
// Tikfout in één woord: 1 letter ("Mat" = "Matt"), bij woorden vanaf 8 tekens 2 ("Panellan" = "Panellakk").
function woord_tikfout(string $x, string $y): bool { if (ctype_digit($x) || ctype_digit($y)) return false; $n = min(strlen($x), strlen($y)); return $n >= 3 && levenshtein($x, $y) <= ($n >= 8 ? 2 : 1); }
// EAN/GTIN vergelijkbaar maken (voorloopnullen van GTIN-14 weg).
function ean_norm(?string $e): string { $e = preg_replace('/\D/', '', (string)$e); return strlen($e) >= 8 ? ltrim($e, '0') : ''; }
function schoon(string $t): string { return trim(preg_replace('/(\|\d+)+/', '', $t)); }

// ---------- adapter: Lightspeed (publieke ?format=json) ----------
function ls_json(string $url): ?array {
    $u = $url . (strpos($url, '?') === false ? '?' : '&') . 'format=json';
    $b = haal($u, $code);
    return $b ? json_decode($b, true) : null;
}
// Volledige catalogusscan: alle zichtbare producten van de shop (100 per pagina), gefilterd op merk.
// Controles: aantal in de catalogus == aantal productpagina's in de sitemap, en de merkpagina mist niets.
function sitemap_producten(string $home): ?int {
    $x = haal($home . 'sitemap.xml', $c); if (!$x) return null;
    preg_match_all('#<loc>([^<]+)</loc>#', $x, $m);
    return count(array_filter($m[1], function ($u) { return substr($u, -5) === '.html'; }));
}
// Inventaris in stappen (elke stap max ~20 s), zodat de server nooit een time-out geeft.
// Fases: catalogus (hele shop, 100 per pagina) → merkpagina (controle) → sitemap (controle) → producten (per product alle varianten) → klaar.
function ls_stap(array $bron, int $run, array &$st, float $tot): void {
    $base = 'https://' . $bron['domein'] . '/';
    while (microtime(true) < $tot) {
        if ($st['fase'] === 'catalogus') {
            $d = ls_json($base . 'collection/' . ($st['page'] > 1 ? "page{$st['page']}.html" : '') . '?limit=100');
            if (!$d) { $st['log'][] = "catalogus pagina {$st['page']} niet leesbaar"; $st['fout']++; $st['fase'] = 'merkpagina'; continue; }
            $c = $d['collection'] ?? []; $st['pages'] = (int)($c['pages'] ?? 1); $st['opgave'] = (int)($c['count'] ?? 0);
            foreach (($c['products'] ?? []) as $p) {
                $st['n_alle']++;
                $merk = is_array($p['brand'] ?? null) ? ($p['brand']['title'] ?? '') : '';
                $titel = ($p['fulltitle'] ?? '') . ' ' . ($p['title'] ?? '');
                if ($merk !== '' && stripos($merk, merk()) !== false) { $st['urls'][$p['url']] = 1; $st['op_merk']++; }
                elseif (preg_match('/(' . merk_lijnen() . ')/i', $titel)) { $st['urls'][$p['url']] = 1; $st['op_titel'][] = trim($p['title'] ?? $p['url']); }
            }
            if (++$st['page'] > $st['pages']) $st['fase'] = 'merkpagina';
        } elseif ($st['fase'] === 'merkpagina') {
            if ($bron['listing_url'] === '' || strpos($bron['listing_url'], '(API)') !== false) { $st['fase'] = 'sitemap'; continue; }
            $d = ls_json(rtrim($bron['listing_url'], '/') . '/' . ($st['mpage'] > 1 ? "page{$st['mpage']}.html" : '') . '?limit=100'); $c = $d['collection'] ?? [];
            foreach (($c['products'] ?? []) as $p) $st['mp'][$p['url']] = 1;
            if (!$d || ++$st['mpage'] > (int)($c['pages'] ?? 1)) $st['fase'] = 'sitemap';
        } elseif ($st['fase'] === 'sitemap') {
            $st['sitemap'] = sitemap_producten($base);
            $st['alleen_scan'] = array_values(array_diff(array_keys($st['urls']), array_keys($st['mp'])));
            $st['alleen_merkpagina'] = array_values(array_diff(array_keys($st['mp']), array_keys($st['urls'])));
            foreach ($st['alleen_merkpagina'] as $u) $st['urls'][$u] = 1; // vangnet
            $st['lijst'] = array_keys($st['urls']); $st['i'] = 0; $st['fase'] = 'producten';
            $st['log'][] = $st['n_alle'] . ' producten in de catalogus gescand, ' . count($st['lijst']) . ' ' . merk() . '-producten gevonden';
        } elseif ($st['fase'] === 'producten') {
            if ($st['i'] >= count($st['lijst'])) { $st['fase'] = 'klaar'; break; }
            $purl = $st['lijst'][$st['i']++];
            $d = ls_json($base . ltrim($purl, '/')); $p = $d['product'] ?? null;
            if (!$p) { $st['fout']++; $st['log'][] = "product niet leesbaar: $purl"; continue; }
            $pid = upsert_product($bron['id'], (string)$p['id'], $p['fulltitle'] ?? $p['title'], $base . ltrim($p['url'], '/'), $p['brand']['title'] ?? '', $st['t']);
            $st['np']++;
            $vars = $p['variants'] ?: [['id' => $p['vid'] ?? $p['id'], 'title' => $p['variant'] ?? 'standaard', 'ean' => $p['ean'] ?? '', 'sku' => $p['sku'] ?? '', 'price' => $p['price'], 'stock' => $p['stock'] ?? []]];
            foreach ($vars as $v) {
                $titel = schoon((string)($v['title'] ?? '')); [$l, $std] = inhoud($titel); if ($l === null) [$l, $std] = inhoud((string)($p['fulltitle'] ?? $p['title'] ?? ''));
                $vid = upsert_variant($pid, (string)$v['id'], $titel, (string)($v['ean'] ?? ''), (string)($v['sku'] ?? ''), $l, $std, kleurtype($titel), $st['t']);
                $pr = $v['price'] ?? []; $old = (float)($pr['price_old_incl'] ?? 0);
                meet($vid, $run, (float)$pr['price_incl'], $old > (float)$pr['price_incl'] ? $old : null, !empty($v['stock']['available']) ? 1 : 0, $v['stock']['delivery']['title'] ?? '', 'lightspeed json', $st['t']);
                $st['nv']++;
            }
        } else break;
    }
    if ($st['fase'] === 'klaar') {
        $dekking = ['catalogus' => $st['n_alle'], 'catalogus_opgave' => $st['opgave'], 'sitemap' => $st['sitemap'], 'op_merk' => $st['op_merk'], 'op_titel' => $st['op_titel'],
            'merkpagina' => count($st['mp']), 'alleen_scan' => $st['alleen_scan'], 'alleen_merkpagina' => $st['alleen_merkpagina']];
        db()->prepare('UPDATE run SET dekking=?, methode=? WHERE id=?')->execute([json_encode($dekking, JSON_UNESCAPED_UNICODE), 'hele shop gescand (publieke catalogus)', $run]);
    }
}

// ---------- adapter: Lightspeed API (alleen onze eigen shop, sleutels in config.php) ----------
function api_ingesteld(): bool { global $CFG; return !empty($CFG['api']['key']) && !empty($CFG['api']['secret']); }
// Basis-URL van de API: cluster (EU/US) en taal van de shop. We proberen de taal uit config.php eerst, daarna de gangbare talen,
// en controleren via /shop.json of de sleutel bij ONZE shop hoort. De werkende combinatie wordt onthouden.
function api_basis(): string {
    global $CFG; static $b = null; if ($b) return $b;
    $sleutel = 'api_basis_' . substr(md5($CFG['api']['key'] . $CFG['api']['secret']), 0, 8);
    if ($b = instelling($sleutel)) return $b;
    $codes = [];
    foreach (['api.webshopapp.com', 'api.shoplightspeed.com'] as $host)
        foreach (array_unique([$CFG['api']['taal'] ?? 'nl', 'nl', 'de', 'en', 'fr', 'es', 'it']) as $taal) {
            $ch = curl_init("https://$host/$taal/shop.json");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => stap_timeout(15), CURLOPT_USERPWD => $CFG['api']['key'] . ':' . $CFG['api']['secret']]);
            $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); usleep(400000); $codes[] = $code;
            if ($code === 401 || $code === 403) break;        // sleutel onbekend op dit cluster: volgende cluster
            if ($code !== 200) continue;                       // taal niet actief in deze shop: volgende taal
            $tekst = strtolower((string)$r);
            if (site_host() !== '' && strpos($tekst, site_host()) === false && preg_match('/"maindomain"\s*:\s*"(?:www\.)?([a-z0-9.-]+)"/', $tekst, $m))
                throw new RuntimeException('deze API-sleutel hoort bij de shop ' . $m[1] . ', niet bij ' . site_host() . '. Zet de sleutel van ' . site_host() . ' in config.php.');
            return $b = instelling($sleutel, "https://$host/$taal");
        }
    throw new RuntimeException(in_array(401, $codes, true) || in_array(403, $codes, true)
        ? 'de API-sleutel of het secret wordt door Lightspeed geweigerd (HTTP 401). Controleer beide in config.php en de rechten van de sleutel.'
        : 'Lightspeed vindt geen shop bij deze sleutel in de talen ' . implode(', ', array_unique([$CFG['api']['taal'] ?? 'nl', 'nl', 'de', 'en', 'fr', 'es', 'it'])) . ' (HTTP ' . implode('/', array_unique($codes)) . ').');
}
function api(string $pad, array $q = []) {
    global $CFG;
    $url = api_basis() . $pad . ($q ? '?' . http_build_query($q) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => stap_timeout(25), CURLOPT_USERPWD => $CFG['api']['key'] . ':' . $CFG['api']['secret']]);
    $b = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    usleep(600000); // ruim onder de rate limit
    if ($code !== 200) throw new RuntimeException("API $pad gaf HTTP $code");
    return json_decode($b, true);
}
function api_alles(string $res): array {
    $uit = []; $page = 1;
    do { $d = api("/$res.json", ['limit' => 250, 'page' => $page]); $rij = $d[$res] ?? []; $uit = array_merge($uit, $rij); $page++; } while (count($rij) === 250);
    return $uit;
}
function inventaris_api(array $bron, int $run, array &$log): array {
    $merkIds = [];
    foreach (api_alles('brands') as $b) if (stripos($b['title'] ?? '', merk()) !== false) $merkIds[] = (int)$b['id'];
    $producten = api_alles('products'); $varianten = api_alles('variants');
    $mijn = []; $opTitel = [];
    foreach ($producten as $p) {
        $bid = is_array($p['brand'] ?? null) ? (int)($p['brand']['resource']['id'] ?? 0) : 0;
        if (in_array($bid, $merkIds, true)) $mijn[(int)$p['id']] = $p;
        elseif (stripos(($p['title'] ?? '') . ' ' . ($p['fulltitle'] ?? ''), merk()) !== false || preg_match('/(' . merk_lijnen() . ')/i', ($p['title'] ?? '') . ' ' . ($p['fulltitle'] ?? ''))) { $mijn[(int)$p['id']] = $p; $opTitel[] = $p['title'] ?? ''; } // merk-veld, merknaam of productlijn in de titel
    }
    $zichtbaar = count(array_filter($mijn, function ($p) { return !empty($p['isVisible']); }));
    $t = nu(); $np = 0; $nv = 0; $pidMap = [];
    foreach ($mijn as $id => $p) {
        $pid = upsert_product($bron['id'], (string)$id, $p['fulltitle'] ?? $p['title'], 'https://' . $bron['domein'] . '/' . ($p['url'] ?? '') . '.html', merk(), $t);
        db()->prepare('UPDATE product SET zichtbaar=? WHERE id=?')->execute([!empty($p['isVisible']) ? 1 : 0, $pid]);
        $pidMap[$id] = $pid; $np++;
    }
    foreach ($varianten as $v) {
        $id = (int)($v['product']['resource']['id'] ?? 0); if (!isset($pidMap[$id])) continue;
        $titel = schoon((string)($v['title'] ?? '')); [$l, $std] = inhoud($titel); if ($l === null) [$l, $std] = inhoud((string)($mijn[$id]['fulltitle'] ?? $mijn[$id]['title'] ?? '')); // inhoud soms alleen in de productnaam
        $vid = upsert_variant($pidMap[$id], (string)$v['id'], $titel, (string)($v['ean'] ?? ''), (string)($v['sku'] ?? ''), $l, $std, kleurtype($titel), $t);
        $oud = (float)($v['oldPriceIncl'] ?? 0); $prijs = (float)($v['priceIncl'] ?? 0);
        $voorraad = empty($v['stockTracking']) || $v['stockTracking'] === 'disabled' || (int)($v['stockLevel'] ?? 0) > 0 ? 1 : 0;
        meet($vid, $run, $prijs, $oud > $prijs ? $oud : null, $voorraad, '', 'lightspeed api', $t); $nv++;
    }
    global $CFG;
    $host = preg_replace('#^https?://#', '', rtrim($CFG['site'], '/'));
    db()->prepare("UPDATE bron SET platform='lightspeed', domein=?, listing_url=? WHERE id=?")->execute([$host, 'https://' . $host . '/ (API)', $bron['id']]);
    $dekking = ['catalogus' => count($producten), 'op_merk' => $np - count($opTitel), 'op_titel' => $opTitel, 'zichtbaar' => $zichtbaar, 'niet_zichtbaar' => $np - $zichtbaar];
    db()->prepare('UPDATE run SET dekking=?, methode=? WHERE id=?')->execute([json_encode($dekking, JSON_UNESCAPED_UNICODE), 'Lightspeed API (volledige catalogus, ook niet-zichtbaar)', $run]);
    $log[] = count($producten) . ' producten via de API, ' . $np . ' ' . merk() . '-producten (' . $zichtbaar . ' zichtbaar in de shop)';
    return [$np, $nv, 0];
}

// ---------- opslag ----------
function upsert_product(int $bron, string $ext, string $titel, string $url, string $merk, string $t): int {
    $q = db()->prepare('INSERT INTO product(bron_id,ext_id,titel,url,merk,eerst_gezien,laatst_gezien) VALUES(?,?,?,?,?,?,?)
        ON CONFLICT(bron_id,ext_id) DO UPDATE SET titel=excluded.titel,url=excluded.url,merk=excluded.merk,laatst_gezien=excluded.laatst_gezien');
    $q->execute([$bron, $ext, $titel, $url, $merk, $t, $t]);
    $s = db()->prepare('SELECT id FROM product WHERE bron_id=? AND ext_id=?'); $s->execute([$bron, $ext]);
    return (int)$s->fetchColumn();
}
function upsert_variant(int $pid, string $ext, string $titel, string $ean, string $sku, $l, $std, string $kt, string $t): int {
    $q = db()->prepare('INSERT INTO variant(product_id,ext_id,titel,ean,sku,inhoud_l,inhoud_std,kleurtype,eerst_gezien,laatst_gezien) VALUES(?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(product_id,ext_id) DO UPDATE SET titel=excluded.titel,ean=excluded.ean,sku=excluded.sku,inhoud_l=excluded.inhoud_l,
        inhoud_std=excluded.inhoud_std,kleurtype=excluded.kleurtype,laatst_gezien=excluded.laatst_gezien');
    $q->execute([$pid, $ext, $titel, $ean, $sku, $l, $std, $kt, $t, $t]);
    $s = db()->prepare('SELECT id FROM variant WHERE product_id=? AND ext_id=?'); $s->execute([$pid, $ext]);
    return (int)$s->fetchColumn();
}
function meet(int $vid, int $run, float $prijs, ?float $van, int $voorraad, string $lever, string $veld, string $t): void {
    db()->prepare('INSERT INTO meting(variant_id,run_id,prijs,van_prijs,op_voorraad,levertijd,bron_veld,tijd) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$vid, $run, $prijs, $van, $voorraad, $lever, $veld, $t]);
}

// ---------- bronnen beheren ----------
function merk(): string { global $CFG; return $CFG['merk'] ?? ''; }

// Zoek zelf het merk-aanbod van een shop, vanaf alleen de homepage.
function zoek_merkpagina(string $home, string $platform, array &$log): ?string {
    if (in_array($platform, GEN_PLATFORMS, true)) { $log[] = 'volledige scan: sitemap + ' . $platform . '-catalogus'; return $home . ' (volledige scan)'; }
    if ($platform !== 'lightspeed') { $log[] = "platform '$platform' is niet te lezen"; return null; }
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', merk()));
    foreach (["brands/$slug/", "search/" . rawurlencode(strtolower(merk())) . "/"] as $pad) {
        $d = ls_json($home . $pad);
        $n = (int)($d['collection']['count'] ?? 0);
        if ($n > 0) { $log[] = "$n " . merk() . "-producten op $pad"; return $home . $pad; }
    }
    return null;
}

// Probeer het domein met en zonder www: kies de variant waar het merk-aanbod echt te vinden is.
function detecteer(string $invoer): array {
    $invoer = trim($invoer);
    if (!preg_match('#^https?://#i', $invoer)) $invoer = 'https://' . $invoer;
    $h = strtolower((string)parse_url($invoer, PHP_URL_HOST));
    $kaal = preg_replace('/^www\./', '', $h);
    $beste = null;
    foreach (array_unique(['www.' . $kaal, $kaal]) as $host) {
        $log = []; $home = 'https://' . $host . '/';
        $html = haal_x($home, $code, $eind) ?? '';
        if ($html !== '' && $eind) { $h2 = strtolower((string)parse_url($eind, PHP_URL_HOST)); if ($h2 && preg_replace('/^www\./', '', $h2) === $kaal) { $host = $h2; $home = 'https://' . $host . '/'; } }
        $platform = $code === 403 ? 'geblokkeerd (HTTP 403)' : ($code === 0 ? 'niet bereikbaar' : herken_platform($html));
        $listing = zoek_merkpagina($home, $platform, $log);
        $r = ['host' => $host, 'platform' => $platform, 'listing' => $listing, 'log' => $log, 'code' => $code, 'bereikbaar' => $html !== ''];
        if ($listing) return $r;
        if (!$beste || ($beste['platform'] === 'onbekend' || $beste['platform'] === 'niet bereikbaar')) $beste = $r;
    }
    return $beste;
}
function bron_toevoegen(string $naam, string $url, string $land = '', int $eigen = 0): array {
    $d = detecteer($url);
    $land = $land ?: (land_van($d['host']) ?? $GLOBALS['CFG']['land']);
    if (empty($d['bereikbaar'])) return ['id' => 0, 'log' => ['geen website bereikbaar op ' . $d['host'] . ' (HTTP ' . (int)($d['code'] ?? 0) . ')'], 'gevonden' => null, 'platform' => $d['platform'], 'host' => $d['host'], 'geweigerd' => true];
    $dub = db()->prepare("SELECT id FROM bron WHERE REPLACE(domein,'www.','')=?"); $dub->execute([preg_replace('/^www\./', '', $d['host'])]);
    if ($x = $dub->fetchColumn()) return ['id' => (int)$x, 'log' => ['staat al in de lijst'], 'gevonden' => null, 'platform' => $d['platform'], 'host' => $d['host'], 'geweigerd' => true];
    $naam = trim($naam) !== '' ? trim($naam) : preg_replace('/^www\./', '', $d['host']);
    db()->prepare('INSERT INTO bron(naam,domein,platform,land,listing_url,is_eigen,toegevoegd) VALUES(?,?,?,?,?,?,?)')
        ->execute([$naam, $d['host'], $d['platform'], $land, $d['listing'] ?? '', $eigen, nu()]);
    return ['id' => (int)db()->lastInsertId(), 'log' => $d['log'], 'gevonden' => $d['listing'], 'platform' => $d['platform'], 'host' => $d['host'], 'land' => $land];
}
function bron_herdetecteer(int $id): array {
    $q = db()->prepare('SELECT * FROM bron WHERE id=?'); $q->execute([$id]); $b = $q->fetch();
    $d = detecteer($b['domein']);
    db()->prepare('UPDATE bron SET domein=?, platform=?, listing_url=? WHERE id=?')->execute([$d['host'], $d['platform'], $d['listing'] ?? '', $id]);
    return ['id' => $id, 'log' => $d['log'], 'gevonden' => $d['listing'], 'platform' => $d['platform'], 'host' => $d['host']];
}
function bron_verwijderen(int $id): void { db()->prepare('DELETE FROM bron WHERE id=?')->execute([$id]); }
function bronnen(): array { return db()->query('SELECT * FROM bron ORDER BY is_eigen DESC, naam')->fetchAll(); }

function run_start(int $bronId): int {
    db()->prepare('INSERT INTO run(bron_id,start,state) VALUES(?,?,?)')->execute([$bronId, nu(), json_encode(['fase' => 'start'])]);
    return (int)db()->lastInsertId();
}
// Eén stap van een lopende inventaris. Geeft voortgang terug; 'klaar' = true als hij af is.
function run_stap(int $run, float $budget = 20.0): array {
    $q = db()->prepare('SELECT r.*, b.id bid FROM run r JOIN bron b ON b.id=r.bron_id WHERE r.id=?'); $q->execute([$run]); $r = $q->fetch();
    if (!$r) return ['klaar' => true, 'tekst' => 'onbekende run'];
    if ($r['eind']) return ['klaar' => true, 'tekst' => 'klaar'];
    // slot: maar één stap tegelijk per run (meerdere tabbladen open = geen dubbel werk)
    $l = db()->prepare('UPDATE run SET slot=? WHERE id=? AND (slot IS NULL OR slot < ?)'); $l->execute([time(), $run, time() - 60]);
    if (!$l->rowCount()) { $v = json_decode((string)$r['voortgang'], true) ?: ['pct' => 0, 'tekst' => 'Bezig…']; return ['klaar' => false] + $v; }
    $b = db()->prepare('SELECT * FROM bron WHERE id=?'); $b->execute([$r['bid']]); $bron = $b->fetch();
    $st = json_decode((string)$r['state'], true) ?: ['fase' => 'start'];
    $GLOBALS['SCAN_LAND'] = $bron['land'] ?: $GLOBALS['CFG']['land'];
    $tot = microtime(true) + $budget; $GLOBALS['STAP_EIND'] = $tot + 22;
    try {
        if ($st['fase'] === 'start') {
            $st = ['fase' => 'catalogus', 'page' => 1, 'pages' => null, 'opgave' => null, 'n_alle' => 0, 'urls' => [], 'op_merk' => 0, 'op_titel' => [], 'mp' => [], 'mpage' => 1,
                   'sitemap' => null, 'lijst' => [], 'i' => 0, 'np' => 0, 'nv' => 0, 'fout' => 0, 'log' => [], 't' => $r['start'], 'alleen_scan' => [], 'alleen_merkpagina' => []];
            if ($bron['is_eigen'] && api_ingesteld()) {
                $log = []; [$np, $nv, $f] = inventaris_api($bron, $run, $log);
                $st = ['fase' => 'klaar', 'np' => $np, 'nv' => $nv, 'fout' => $f, 'log' => $log];
            } elseif (in_array($bron['platform'], GEN_PLATFORMS, true)) {
                $st = gen_start($bron, $r['start']);
            } elseif ($bron['platform'] !== 'lightspeed') {
                $st = ['fase' => 'klaar', 'np' => 0, 'nv' => 0, 'fout' => 1, 'log' => ["platform '{$bron['platform']}' is niet te lezen"]];
            }
        }
        if ($st['fase'] !== 'klaar') { if (strpos($st['fase'], 'g_') === 0) gen_stap($bron, $run, $st, $tot); else ls_stap($bron, $run, $st, $tot); }
    } catch (Throwable $ex) { $st['fase'] = 'klaar'; $st['fout'] = ($st['fout'] ?? 0) + 1; $st['log'][] = 'fout: ' . $ex->getMessage(); }
    if ($st['fase'] === 'klaar') {
        db()->prepare('UPDATE run SET eind=?,producten=?,varianten=?,fouten=?,log=?,state=NULL,slot=NULL WHERE id=?')->execute([nu(), $st['np'], $st['nv'], $st['fout'], implode("\n", $st['log']), $run]);
        return ['klaar' => true, 'pct' => 100, 'tekst' => "{$st['np']} producten, {$st['nv']} varianten", 'fouten' => $st['fout'], 'log' => $st['log']];
    }
    db()->prepare('UPDATE run SET state=? WHERE id=?')->execute([json_encode($st, JSON_UNESCAPED_UNICODE), $run]);
    $voortgangLater = true;
    $tekst = $st['fase'] === 'catalogus' ? 'Hele shop scannen: pagina ' . ($st['page'] - 1) . ' van ' . ($st['pages'] ?? '?') . ' · ' . count($st['urls']) . ' ' . merk() . ' gevonden'
        : ($st['fase'] === 'producten' ? 'Producten ophalen: ' . $st['i'] . ' van ' . count($st['lijst']) : 'Controle ' . $st['fase']);
    $pct = $st['fase'] === 'catalogus' ? (int)round(70 * max(0, $st['page'] - 1) / max(1, (int)($st['pages'] ?? 1)))
        : ($st['fase'] === 'producten' ? 75 + (int)round(25 * $st['i'] / max(1, count($st['lijst']))) : 72);
    $v = ['pct' => min(99, $pct), 'tekst' => $tekst];
    if (strpos($st['fase'], 'g_') === 0) $v = gen_voortgang($st);
    db()->prepare('UPDATE run SET voortgang=?, slot=NULL WHERE id=?')->execute([json_encode($v, JSON_UNESCAPED_UNICODE), $run]);
    return ['klaar' => false] + $v;
}
// lopende runs (om ze vanaf elke pagina door te laten lopen); verlaten runs > 2 uur worden afgesloten
function open_runs(): array {
    db()->prepare("UPDATE run SET eind=?, fouten=1, log='afgebroken (niet afgemaakt)', state=NULL WHERE eind IS NULL AND start < ?")->execute([nu(), date('Y-m-d H:i:s', time() - 7200)]);
    return db()->query('SELECT id, bron_id, voortgang FROM run WHERE eind IS NULL ORDER BY id')->fetchAll();
}
// volledige inventaris in één keer (CLI / cron)
function inventariseer(int $bronId): array {
    $run = run_start($bronId);
    do { $r = run_stap($run, 3600); } while (!$r['klaar']);
    $q = db()->prepare('SELECT * FROM run WHERE id=?'); $q->execute([$run]); $x = $q->fetch();
    return ['run' => $run, 'producten' => (int)$x['producten'], 'varianten' => (int)$x['varianten'], 'fouten' => (int)$x['fouten'], 'log' => explode("\n", (string)$x['log'])];
}

// ---------- CLI ----------
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    db();
    $cmd = $argv[1] ?? 'bronnen';
    if ($cmd === 'toevoegen') { echo json_encode(bron_toevoegen($argv[2], $argv[3], $argv[4] ?? '', (int)($argv[5] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; }
    elseif ($cmd === 'omzet') { echo 'indicatie: ' . omzet_indicatie() . " producten\n"; $r = omzet_start(); do { $x = omzet_stap($r, 3600); echo $x['tekst'], "\n"; } while (!$x['klaar']); }
    elseif ($cmd === 'verwijderen') { bron_verwijderen((int)$argv[2]); echo "verwijderd\n"; }
    elseif ($cmd === 'inventaris') {
        $ids = ($argv[2] ?? 'alle') === 'alle' ? array_column(array_filter(bronnen(), fn($b) => $b['actief']), 'id') : [(int)$argv[2]];
        foreach ($ids as $id) echo json_encode(inventariseer((int)$id), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else foreach (bronnen() as $b) echo "{$b['id']}  {$b['naam']}  {$b['platform']}  {$b['listing_url']}\n";
}

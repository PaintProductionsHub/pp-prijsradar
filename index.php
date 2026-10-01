<?php
// Prijsradar mini: weergave in het mockup-ontwerp. Stap 1 = inventaris + bronnen beheren.
require __DIR__ . '/radar.php';
require __DIR__ . '/grafieken.php';
$lokaal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$lokaal && (empty($CFG['wachtwoord']) || $CFG['wachtwoord'] === 'VERANDER-MIJ')) { http_response_code(503); exit('Zet eerst een eigen wachtwoord in config.php'); }
if (!$lokaal && ($_SERVER['PHP_AUTH_PW'] ?? '') !== $CFG['wachtwoord']) {
    header('WWW-Authenticate: Basic realm="Prijsradar"'); http_response_code(401); exit('Login nodig');
}
header('X-Robots-Tag: noindex, nofollow');
session_start();
// Hoort deze config bij deze server? (bv. de config van jotun-specialist.nl per ongeluk op data.jotunfarbe.de) Dan stoppen, nooit andermans data tonen.
$hier = preg_replace('/^(data|radar|prijsradar|www)\./', '', strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')));
if (!$lokaal && $hier !== '' && site_host() !== '' && $hier !== site_host()) { http_response_code(500); exit('config.php hoort bij ' . htmlspecialchars(site_host()) . ', maar deze server is ' . htmlspecialchars($hier) . '. Zet de config.php van ' . htmlspecialchars($hier) . ' op deze server.'); }
try { db(); } catch (RuntimeException $ex) { http_response_code(500); exit(htmlspecialchars($ex->getMessage())); }
$MERK = merk();
$LANDEN = markten();
if (isset($_GET['land']) && in_array($_GET['land'], $LANDEN, true)) $_SESSION['land'] = $_GET['land'];
$LAND = in_array($_SESSION['land'] ?? '', $LANDEN, true) ? $_SESSION['land'] : $CFG['land'];

// wat is er gevonden? eerlijk: alleen 'ok' als er echt merk-aanbod is
function vondst(array $r): array {
    global $MERK;
    $pl = $r['platform'] ?? '?'; $h = $r['host'] ?? '';
    if ($r['gevonden']) return ['ok' => true, 'tekst' => "$h · markt " . ($r['land'] ?? '?') . " · $pl · " . implode(' · ', $r['log'])];
    $waarom = strpos($pl, 'geblokkeerd') === 0 || $pl === 'niet bereikbaar' ? $pl
        : ($pl === 'lightspeed' ? "geen $MERK-merkpagina of zoekresultaat voor $MERK op deze Lightspeed-shop" : "platform '$pl' kan de radar niet lezen");
    return ['ok' => false, 'tekst' => "$h · niets gevonden: $waarom"];
}
// ---------- acties ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['actie'] ?? '';
    // stappen draaien lang: sessie meteen vrijgeven, anders wacht elke andere pagina hierop (504)
    if (in_array($a, ['stap', 'omzetstap', 'volgorde'], true)) session_write_close();
    $naar = '?tab=' . urlencode($_POST['tab'] ?? 'overzicht');
    $host = function ($u) { $u = trim($u); if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u; return preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST))); };
    if ($a === 'toevoegen' && !empty($_POST['url']) && $host($_POST['url']) === $host($CFG['site'])) {
        $_SESSION['melding'] = ['ok' => false, 'tekst' => 'Dit is de referentieshop zelf (' . wn() . ').']; $a = ''; $naar = '?tab=concurrenten';
    }
    if ($a === 'basis') {
        $bestaand = null; foreach (bronnen() as $bb) if ($bb['is_eigen']) $bestaand = $bb;
        if (api_ingesteld()) { // met API is onze eigen shop altijd volledig leesbaar: direct aanmaken en ophalen
            $h = preg_replace('#^https?://#', '', rtrim($CFG['site'], '/'));
            if (!$bestaand) { db()->prepare('INSERT INTO bron(naam,domein,platform,land,listing_url,is_eigen,toegevoegd) VALUES(?,?,?,?,?,1,?)')->execute([preg_replace('/^www\./', '', $h), $h, 'lightspeed', $CFG['land'], 'https://' . $h . '/ (API)', nu()]); $bid = (int)db()->lastInsertId(); }
            else $bid = (int)$bestaand['id'];
            $open = array_map('intval', array_column(open_runs(), 'bron_id')); if (!in_array($bid, $open, true)) run_start($bid);
        } else {
            $r = $bestaand ? bron_herdetecteer((int)$bestaand['id']) : bron_toevoegen('', $CFG['site'], $CFG['land'], 1);
            $_SESSION['melding'] = vondst($r);
        }
    }
    if ($a === 'toevoegen' && !empty($_POST['url'])) {
        $r = bron_toevoegen((string)($_POST['naam'] ?? ''), trim($_POST['url']), '', 0);
        $naar = '?tab=concurrenten' . (!empty($r['land']) ? '&land=' . $r['land'] : ''); // nieuwe aanbieder uit een ander land: meteen die markt tonen
        if (!empty($r['geweigerd'])) $_SESSION['melding'] = ['ok' => false, 'tekst' => $r['host'] . ': ' . implode(' · ', $r['log'])];
        elseif ($r['gevonden']) run_start((int)$r['id']); // meteen ophalen, in stappen; de voortgangsbalk staat in de rij
        else $_SESSION['melding'] = vondst($r);
    }
    if ($a === 'bronland' && isset(MARKTEN[$_POST['land'] ?? ''])) { db()->prepare('UPDATE bron SET land=? WHERE id=? AND is_eigen=0')->execute([$_POST['land'], (int)$_POST['id']]); $naar = '?tab=concurrenten&land=' . $_POST['land']; }
    if ($a === 'bronnaam' && trim((string)($_POST['naam'] ?? '')) !== '') { db()->prepare('UPDATE bron SET naam=? WHERE id=?')->execute([mb_substr(trim($_POST['naam']), 0, 60), (int)$_POST['id']]); $naar = '?tab=concurrenten'; }
    if ($a === 'sortering' && in_array($_POST['s'] ?? '', ['hand', 'omzet', 'naam'], true)) { instelling('sortering', $_POST['s']); $naar = '?tab=shop'; }
    if ($a === 'omzet') { if (api_ingesteld()) { try { omzet_indicatie(); } catch (Throwable $ex) {} omzet_start(); } $naar = '?tab=shop'; }
    if ($a === 'marge') { if (api_ingesteld()) { try { [$n, $met, $ok] = marge_ophalen(); $_SESSION['melding'] = ['ok' => true, 'tekst' => "Marge bijgewerkt: $met van $n varianten hebben een inkoopprijs in Lightspeed" . ($ok ? '.' : ', nog niet alles gelezen: klik nogmaals.')]; } catch (Throwable $ex) { $_SESSION['melding'] = ['ok' => false, 'tekst' => 'Marge ophalen mislukt: ' . $ex->getMessage()]; } } $naar = '?tab=shop'; }
    if ($a === 'minmarge') { $v = trim(str_replace(',', '.', (string)($_POST['min'] ?? ''))); if ($v === '') db()->prepare('DELETE FROM instelling WHERE sleutel=?')->execute(['min_marge']); elseif (is_numeric($v) && $v >= 0 && $v < 95) instelling('min_marge', (string)(float)$v); $naar = '?tab=shop'; }
    if ($a === 'omzetstap') { header('Content-Type: application/json'); echo json_encode(omzet_stap((int)$_POST['run'], 20)); exit; }
    if ($a === 'meenemen') { db()->prepare('UPDATE product SET meenemen=? WHERE id=?')->execute([(int)!empty($_POST['aan']), (int)$_POST['pid']]); $naar = '?tab=shop'; }
    if ($a === 'volgorde') { $i = 0; foreach ((array)($_POST['ids'] ?? []) as $id) db()->prepare('UPDATE product SET volgorde=? WHERE id=?')->execute([++$i, (int)$id]); http_response_code(204); exit; }
    if ($a === 'naam') {
        db()->exec('CREATE TABLE IF NOT EXISTS naam (pid INTEGER, inhoud TEXT, naam TEXT, PRIMARY KEY(pid, inhoud))');
        $n = trim((string)($_POST['naam'] ?? ''));
        if ($n === '') db()->prepare('DELETE FROM naam WHERE pid=? AND inhoud=?')->execute([(int)$_POST['p'], (string)$_POST['inhoud']]);
        else db()->prepare('INSERT OR REPLACE INTO naam VALUES(?,?,?)')->execute([(int)$_POST['p'], (string)$_POST['inhoud'], mb_substr($n, 0, 120)]);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=producten&p=' . (int)$_POST['p'] . '#i' . anker((string)$_POST['inhoud'])); exit;
    }
    if ($a === 'kies_eigen') {
        db()->exec('CREATE TABLE IF NOT EXISTS eigen_keuze (pid INTEGER, inhoud TEXT, vid INTEGER, PRIMARY KEY(pid, inhoud))');
        $h = strtolower((string)($_POST['ons'] ?? 'auto'));
        if ($h === 'auto') db()->prepare('DELETE FROM eigen_keuze WHERE pid=? AND inhoud=?')->execute([(int)$_POST['p'], (string)$_POST['inhoud']]);
        else db()->prepare('INSERT OR REPLACE INTO eigen_keuze VALUES(?,?,?)')->execute([(int)$_POST['p'], (string)$_POST['inhoud'], (int)$h]);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=producten&p=' . (int)$_POST['p'] . '#i' . anker((string)$_POST['inhoud'])); exit;
    }
    if ($a === 'kies') {
        db()->exec('CREATE TABLE IF NOT EXISTS keuze (eigen_vid INTEGER, bron_id INTEGER, hun_vid INTEGER, PRIMARY KEY(eigen_vid, bron_id))');
        $h = strtolower((string)($_POST['hun'] ?? 'auto'));
        if ($h === 'auto') db()->prepare('DELETE FROM keuze WHERE eigen_vid=? AND bron_id=?')->execute([(int)$_POST['eigen'], (int)$_POST['bron']]);
        else db()->prepare('INSERT OR REPLACE INTO keuze VALUES(?,?,?)')->execute([(int)$_POST['eigen'], (int)$_POST['bron'], $h === 'geen' ? -1 : (int)$h]);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=producten&p=' . (int)$_POST['p'] . '#v' . (int)$_POST['eigen']); exit;
    }
    if ($a === 'kolommen') { instelling('kolommen', json_encode(array_values((array)($_POST['kol'] ?? [])))); }
    if ($a === 'verwijderen') { bron_verwijderen((int)$_POST['id']); $_SESSION['melding'] = ['ok' => true, 'tekst' => 'Verwijderd.']; }
    if ($a === 'alles') { $open = array_map('intval', array_column(open_runs(), 'bron_id')); foreach (bronnen() as $bb) if (!in_array((int)$bb['id'], $open, true)) run_start((int)$bb['id']); $naar = '?tab=concurrenten'; }
    if ($a === 'inventaris') {
        $open = array_column(open_runs(), 'bron_id'); if (!in_array((int)$_POST['id'], array_map('intval', $open), true)) run_start((int)$_POST['id']);
        if (!empty($_POST['bron'])) $naar .= '&bron=' . (int)$_POST['bron'];

    }
    if ($a === 'stap') { // één stap van ~20 s, aangeroepen door de pagina zelf
        header('Content-Type: application/json'); $r = run_stap((int)$_POST['run'], max(2.0, min(25.0, (float)($_POST['budget'] ?? 20))));
        if ($r['klaar']) { @session_start(); $_SESSION['melding'] = !empty($r['fouten']) ? ['ok' => false, 'tekst' => 'Mislukt: ' . implode(' · ', $r['log'] ?? [])] : ['ok' => true, 'tekst' => 'Bijgewerkt: ' . $r['tekst'] . '.']; session_write_close(); }
        echo json_encode($r); exit;
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $naar); exit;
}
$melding = $_SESSION['melding'] ?? null; unset($_SESSION['melding']);
$WEEKCHECK = empty($_SESSION['weekcheck']); $_SESSION['weekcheck'] = 1;
session_write_close(); // de rest van de pagina houdt de sessie niet vast

// ---------- helpers ----------
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Startstappen voor een nieuwe site: tot alles gedaan is staat dit bovenaan het Overzicht
function startstappen($eigen, array $conc): string {
    omzet_db(); marge_db();
    $st = [
        [wn() . ' ophalen', api_ingesteld() ? 'via de Lightspeed API (sleutel staat in config.php)' : 'zet eerst de Lightspeed API-sleutel in config.php, of haal de shop op via de website', $eigen && laatste_run((int)$eigen['id']), '?tab=shop'],
        ['Marge berekenen', wn() . ' → Dekking → "Bereken marge per product" (inkoopprijs uit Lightspeed)', (bool)db()->query('SELECT COUNT(*) FROM marge')->fetchColumn(), '?tab=shop'],
        ['Best verkochte producten ophalen', wn() . ' → Dekking, ±2 uur met het tabblad open (daarna gaat het in seconden)', (bool)db()->query('SELECT (SELECT COUNT(*) FROM verkoop_regel) + (SELECT COUNT(*) FROM verkocht_indicatie)')->fetchColumn(), '?tab=shop'],
        ['Aanbieders toevoegen', 'Aanbieders → website invullen; minimaal ' . drempel('min_aanbieders') . ' voor een betrouwbare mediaan', db()->query('SELECT COUNT(DISTINCT p.bron_id) FROM product p JOIN bron b ON b.id=p.bron_id WHERE b.is_eigen=0')->fetchColumn() >= drempel('min_aanbieders'), '?tab=concurrenten'],
    ];
    $klaar = count(array_filter($st, function ($x) { return $x[2]; }));
    if ($klaar === count($st)) return '';
    $h = '<div class="card start"><h2>Aan de slag · ' . $klaar . ' van ' . count($st) . ' klaar</h2><p class="gr-uitleg">Daarna volgt de rest vanzelf: prijzen, grafieken en een wekelijkse update van alle aanbieders.</p><ol>';
    foreach ($st as $i => $x) $h .= '<li class="' . ($x[2] ? 'ok' : '') . '"><b>' . ($x[2] ? '✓' : $i + 1) . '</b><div><a href="' . $x[3] . '">' . e($x[0]) . '</a><small>' . e($x[1]) . '</small></div></li>';
    return $h . '</ol></div>';
}
function eur($v) { return $v === null ? '' : '€' . number_format((float)$v, 2, ',', '.'); }
function liter($v) { if ($v === null) return '?'; $v = (float)$v; $kg = $v >= 10000; if ($kg) $v -= 10000; return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',') . ($kg ? ' kg' : ' L'); }
// Maat zoals hij getoond wordt: "2,7 L" of "25 kg" (inhoud_std ≥ 10000 = kilo's), en de eenheid voor een prijs per eenheid.
function maat(array $r): string { return (float)($r['inhoud_std'] ?? 0) >= 10000 ? liter($r['inhoud_std']) : liter($r['inhoud_l']); }
function eenheid(array $r): string { return (float)($r['inhoud_std'] ?? 0) >= 10000 ? 'kg' : 'L'; }
// kleur vanuit ONS perspectief: rood = wij duurder, groen = wij goedkoper, 0 = neutraal.
// $kant 'wij' = het getal is onze prijs t.o.v. iets; 'hen' = het getal is hun prijs t.o.v. ons; 'neutraal' = geen kleur
function pct($v, $kant = 'wij') { if ($v === null) return '<span class="muted">n.v.t.</span>'; $c = '';
    if (abs($v) >= 0.05 && $kant !== 'neutraal') { $wijDuurder = $kant === 'wij' ? $v > 0 : $v < 0; $c = $wijDuurder ? 'rood' : 'groen'; }
    return '<span class="num ' . $c . '">' . ($v > 0.05 ? '+' : '') . number_format($v, 1, ',', '') . '%</span>'; }
function chip($t, $k = '') { return '<span class="chip ' . $k . '">' . e($t) . '</span>'; }
// in de radar: handmatige keuze, anders automatisch (online en met inhoud, dus geen folders of waaiers)
define('IN_RADAR', "COALESCE(p.meenemen, CASE WHEN COALESCE(p.zichtbaar,1)=1 AND EXISTS(SELECT 1 FROM variant v2 WHERE v2.product_id=p.id AND v2.inhoud_l IS NOT NULL) THEN 1 ELSE 0 END)");
// Kleurtype altijd vers bepalen bij het lezen, niet uit de database: eerst uit de variantnaam, anders uit de productnaam
// (shops met één variant "standaard" zetten de kleur in de productnaam). Oude scans met een verouderd kleurtype tellen zo ook goed.
function kleur_nu(array $rows): array { foreach ($rows as &$r) $r['kleurtype'] = kleurtype((string)$r['vtitel']) ?: kleurtype_naam((string)$r['ptitel']); return $rows; }
function aanbod(int $bron, bool $ookOffline = false): array {
    $q = db()->prepare("SELECT p.id pid, p.ext_id, b.is_eigen, p.titel ptitel, p.url, p.zichtbaar, " . IN_RADAR . " in_radar, p.volgorde, v.id vid, v.ext_id vext, v.titel vtitel, v.ean, v.sku, v.inhoud_l, v.inhoud_std, v.kleurtype,
             m.prijs, m.van_prijs, m.op_voorraad, m.levertijd, m.tijd
      FROM product p JOIN bron b ON b.id=p.bron_id JOIN variant v ON v.product_id=p.id
      JOIN meting m ON m.id=(SELECT id FROM meting WHERE variant_id=v.id" . tot_sql() . " ORDER BY tijd DESC, id DESC LIMIT 1)
      WHERE p.bron_id=?" . ($ookOffline ? '' : ' AND ' . IN_RADAR . '=1') . " ORDER BY COALESCE(p.volgorde, 999999), p.titel, v.inhoud_std, v.kleurtype");
    $q->execute([$bron]); $rows = kleur_nu($q->fetchAll());
    if ($rows && (int)$rows[0]['is_eigen'] === 1) {
        $srt = instelling('sortering') ?: 'hand';
        $om = $srt === 'omzet' ? omzet_per_product()['omzet'] : [];
        if ($srt === 'omzet' && !$om) $srt = 'hand'; // meest verkocht kan pas als de omzet ooit is opgehaald
        if ($srt !== 'hand') {
            $pos = []; foreach ($rows as $i => $r) $pos[$r['pid']] = $pos[$r['pid']] ?? $i;
            usort($rows, function ($a, $b) use ($srt, $om, $pos) {
                if ($a['pid'] === $b['pid']) return $pos[$a['pid']] <=> $pos[$b['pid']] ?: ((float)$a['inhoud_std'] <=> (float)$b['inhoud_std']);
                if ($srt === 'omzet') { $x = (float)($om[$b['ext_id']] ?? 0) <=> (float)($om[$a['ext_id']] ?? 0); if ($x) return $x; }
                return strcasecmp(korte_titel($a['ptitel']), korte_titel($b['ptitel'])) ?: ($a['pid'] <=> $b['pid']);
            });
        }
    }
    return $rows;
}
function laatste_run(int $bron) { $q = db()->prepare('SELECT * FROM run WHERE bron_id=? AND eind IS NOT NULL ORDER BY id DESC LIMIT 1'); $q->execute([$bron]); return $q->fetch(); }
function telling(int $bron): array {
    $q = db()->prepare('SELECT COUNT(DISTINCT p.id) p, COUNT(v.id) v FROM product p LEFT JOIN variant v ON v.product_id=p.id WHERE p.bron_id=?');
    $q->execute([$bron]); return $q->fetch();
}
// Dekking van de laatste scan: kunnen we aantonen dat we niets missen?
function dekking_kaart($run, array $extra = []) {
    global $MERK;
    if (!$run || empty($run['dekking'])) return '';
    $d = json_decode($run['dekking'], true); $ok = '<span class="chip ok">✓</span>'; $let = '<span class="chip amb">let op</span>';
    if (($d['route'] ?? '') === 'dubbel') {
        $nb = count($d['niet_bereikt'] ?? []); $twee = $d['sitemap_urls'] > 0 && $d['catalogus'] > 0;
        $oordeel = $nb ? '<span class="chip warn">Niet compleet</span> ' . $nb . ' pagina\'s reageerden niet. Aanbevolen: opnieuw scannen.'
            : ($twee ? '<span class="chip ok">Compleet</span> Twee routes gelezen, samen ' . (int)$d['gevonden'] . ' ' . e($MERK) . '-producten.'
                     : '<span class="chip amb">Eén route</span> ' . ($d['sitemap_urls'] ? 'Alleen de sitemap was leesbaar.' : 'Alleen de catalogus was leesbaar.') . ' Samen ' . (int)$d['gevonden'] . ' ' . e($MERK) . '-producten. Aanbevolen: opnieuw scannen.');
        $r = [['Gelezen via', e($run['methode'] ?? ''), ''],
              ['Route 1: sitemap', number_format((int)$d['sitemap_urls'], 0, ',', '.') . ' pagina\'s · ' . (int)$d['via_sitemap'] . ' ' . e($MERK) . '-producten', $d['sitemap_urls'] ? $ok : $let],
              ['Route 2: catalogus', number_format((int)$d['catalogus'], 0, ',', '.') . ' producten · ' . (int)$d['via_catalogus'] . ' ' . e($MERK) . '-producten', $d['catalogus'] ? $ok : $let]];
        if (!empty($d['prijscontrole'])) { $pc = $d['prijscontrole']; $mk2 = $pc['markt'] ?? [];
            $regels = ['prijs bij 1 stuk, incl. btw'];
            if ($pc['staffel']) $regels[] = $pc['staffel'] . ' producten met staffelprijs: 1 stuk genomen';
            if ($pc['excl']) $regels[] = $pc['excl'] . ' producten tonen excl. btw: omgerekend met ' . rtrim(rtrim(number_format(btw_van((string)db()->query('SELECT land FROM bron WHERE id=' . (int)$run['bron_id'])->fetchColumn()), 1, ',', ''), '0'), ',') . '% btw';
            if ($pc['met_ref']) $regels[] = $pc['bevestigd'] . ' van ' . $pc['met_ref'] . ' bevestigd door productdata op de pagina';
            $afw = in_array($mk2['oordeel'] ?? '', ['erg laag', 'lijkt excl. btw', 'opvallend laag'], true);
            if (!empty($mk2['mediaan'])) $regels[] = 'mediaan ' . number_format($mk2['mediaan'] * 100, 0) . '% van de prijs van ' . wn() . ' over ' . (int)$mk2['n'] . ' varianten' . ($afw ? ' · prijzen erg laag: scan opnieuw of onderzoek waarom' : '');
            $r[] = ['Prijscontrole', e(implode(' · ', $regels)), $afw ? $let : $ok]; }
        if (!empty($d['alleen_sitemap'])) $r[] = ['Alleen via sitemap', count($d['alleen_sitemap']) . ': ' . e(implode(', ', array_slice($d['alleen_sitemap'], 0, 5))) . (count($d['alleen_sitemap']) > 5 ? ' …' : ''), ''];
        if (!empty($d['alleen_catalogus'])) $r[] = ['Alleen via catalogus', count($d['alleen_catalogus']) . ': ' . e(implode(', ', array_slice($d['alleen_catalogus'], 0, 5))) . (count($d['alleen_catalogus']) > 5 ? ' …' : ''), ''];
        $sk = array_values(array_filter($d['overgeslagen'] ?? [], function ($x) { return strpos($x, 'geen productpagina') === false && strpos($x, 'overzichtspagina') === false; }));
        if ($sk) $r[] = ['Niet meegenomen', '<details><summary style="cursor:pointer">' . count($sk) . ' pagina\'s, met reden</summary><div class="muted" style="font-size:12.5px;margin-top:6px">' . implode('<br>', array_map(function ($x) { return e(preg_replace('#^https?://[^/]+#', '', $x)); }, $sk)) . '</div></details>', ''];
        foreach ($extra as $x) $r[] = $x;
        $h = '<div class="card"><h2 style="margin-bottom:10px">Dekking</h2><p style="margin:0 0 12px;font-size:15px">' . $oordeel . '</p><table>';
        foreach ($r as $x) $h .= '<tr><td style="width:220px"><b>' . $x[0] . '</b></td><td>' . $x[1] . '</td><td style="width:90px;text-align:right">' . $x[2] . '</td></tr>';
        return $h . '</table></div>';
    }
    $r = [];
    $r[] = ['Gelezen via', e($run['methode'] ?? ''), ''];
    if (isset($d['sitemap'])) {
        $gelijk = $d['sitemap'] !== null && $d['sitemap'] === $d['catalogus'];
        $r[] = ['Gescand', number_format($d['catalogus'], 0, ',', '.') . ' producten', ''];
        $r[] = ['Sitemap', $d['sitemap'] === null ? 'niet leesbaar' : number_format($d['sitemap'], 0, ',', '.') . ' producten' . ($gelijk ? '' : ', verschil ' . abs($d['sitemap'] - $d['catalogus'])), $gelijk ? $ok : $let];
    } else {
        $r[] = ['Gescand via API', number_format($d['catalogus'], 0, ',', '.') . ' producten', $ok];
    }
    $r[] = [e($MERK), (int)$d['op_merk'] . ' producten', ''];
    $r[] = ['Zonder merklabel', count($d['op_titel']) ? count($d['op_titel']) . ': ' . e(implode(', ', array_slice($d['op_titel'], 0, 6))) : 'geen', count($d['op_titel']) ? $let : $ok];
    if (isset($d['merkpagina'])) $r[] = ['Merkpagina', $d['merkpagina'] . ' producten' . ((count($d['alleen_scan']) || count($d['alleen_merkpagina'])) ? ', ' . (count($d['alleen_scan']) + count($d['alleen_merkpagina'])) . ' verschil' : ''), (count($d['alleen_scan']) || count($d['alleen_merkpagina'])) ? $let : $ok];
    if (isset($d['zichtbaar'])) $r[] = ['Online', (int)$d['zichtbaar'] . ' producten', ''];
    if (isset($d['niet_zichtbaar'])) $r[] = ['Niet online', (int)$d['niet_zichtbaar'] . ' producten', $d['niet_zichtbaar'] ? $let : $ok];
    $oordeel = '';
    if (isset($d['sitemap'])) {
        $compleet = $d['sitemap'] !== null && $d['sitemap'] === $d['catalogus'] && !count($d['alleen_scan'] ?? []) && !count($d['alleen_merkpagina'] ?? []);
        $oordeel = $compleet
            ? '<p style="margin:0 0 12px;font-size:15px"><span class="chip ok">Compleet</span> Alle ' . number_format($d['catalogus'], 0, ',', '.') . ' producten van de shop bekeken, gelijk aan hun sitemap.</p>'
            : '<p style="margin:0 0 12px;font-size:15px"><span class="chip amb">Niet bewezen compleet</span> Scan en sitemap verschillen, zie hieronder.</p>';
    }
    foreach ($extra as $x) $r[] = $x;
    $h = '<div class="card"><h2 style="margin-bottom:10px">Dekking</h2>' . $oordeel . '<table>';
    foreach ($r as $x) $h .= '<tr><td style="width:220px"><b>' . $x[0] . '</b></td><td>' . $x[1] . '</td><td style="width:90px;text-align:right">' . $x[2] . '</td></tr>';
    return $h . '</table></div>';
}
// volledige variantnaam zoals de klant hem ziet: product + variant
function volle_naam($ptitel, $vtitel) { $p = korte_titel($ptitel); $v = trim((string)$vtitel); return ($v === '' || strcasecmp($v, 'standaard') === 0) ? $p : $p . ' ' . $v; }
// voortgangsbalk voor een lopende run (wordt live bijgewerkt door het script onderaan)
function balk(array $run): string {
    $v = json_decode((string)$run['voortgang'], true) ?: ['pct' => 0, 'tekst' => 'Starten…'];
    return '<div class="runbalk" data-run="' . (int)$run['id'] . '"><div class="rb-tekst"><span>' . e($v['tekst']) . '</span><b>' . (int)$v['pct'] . '%</b></div><div class="rb-bak"><div class="rb-vul" style="width:' . (int)$v['pct'] . '%"></div></div></div>';
}
// match-teken: groen vinkje = zeker (zelfde EAN, of exact zelfde productnaam + inhoud), rood vraagteken = onzeker
function matchteken(array $m): string {
    $zeker = !empty($m['zeker']);
    $uitleg = $m['soort'] === 'EAN' ? 'Zeker: zelfde EAN-code' : ($zeker ? 'Zeker: zelfde productnaam en inhoud' : 'Onzeker: productnaam lijkt, controleer');
    return '<span title="' . e($uitleg) . '" style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:99px;font-size:13px;font-weight:700;'
        . ($zeker ? 'background:#e3f1e8;color:#1d6a3d">✓' : 'background:#fbe4e4;color:#b42318">?') . '</span>';
}
// Welke variant van een concurrent zetten we naast onze variant?
// Jouw keuze (tabel keuze) gaat altijd voor; anders de automatische koppeling (zie hieronder).
function kies_voor_bron(array $ons, array $concRows, int $bid): array {
    db()->exec('CREATE TABLE IF NOT EXISTS keuze (eigen_vid INTEGER, bron_id INTEGER, hun_vid INTEGER, PRIMARY KEY(eigen_vid, bron_id))');
    $vanBron = array_values(array_filter($concRows, function ($c) use ($bid) { return (int)$c['bid'] === $bid; }));
    $matches = koppel($ons, $vanBron, true);
    // kandidaten om uit te kiezen: de matches + alles van deze bron met dezelfde inhoud
    $kand = []; foreach ($matches as $m) $kand[(int)$m['vid']] = $m;
    foreach ($vanBron as $c) if ($ons['inhoud_std'] !== null && (string)$c['inhoud_std'] === (string)$ons['inhoud_std'] && !isset($kand[(int)$c['vid']])) $kand[(int)$c['vid']] = $c + ['soort' => 'handmatig', 'zeker' => true];
    $q = db()->prepare('SELECT hun_vid FROM keuze WHERE eigen_vid=? AND bron_id=?'); $q->execute([(int)$ons['vid'], $bid]); $k = $q->fetchColumn();
    if ($k !== false) {
        if ((int)$k === -1) return ['gekozen' => null, 'kand' => $kand, 'door' => 'jij', 'uit' => true];
        if (isset($kand[(int)$k])) { $g = $kand[(int)$k]; if ($g['soort'] === 'handmatig') $g['soort'] = 'handmatig gekozen'; $g['zeker'] = true; return ['gekozen' => $g, 'kand' => $kand, 'door' => 'jij', 'uit' => false]; }
    }
    // Automatisch: gelijk met gelijk. Eerst dezelfde kleurvariant als de onze, dan EAN, dan de beste naam, dan de laagste prijs.
    // Heeft onze variant een kleurtype, dan alleen automatisch naast hetzelfde kleurtype (of een variant zonder kleur-aanduiding):
    // hun Weiß naast onze Wunschfarbe zou de markt vertekenen. Die staat wel als kandidaat, dus je kunt hem zelf kiezen.
    $pool = array_values(array_filter($matches, function ($m) use ($ons) { return $m['auto'] && ($ons['kleurtype'] === '' || $m['kleurtype'] === '' || $m['kleurtype'] === $ons['kleurtype']); }));
    if (!$pool) return ['gekozen' => null, 'kand' => $kand, 'door' => 'auto', 'uit' => false];
    $kt = $ons['kleurtype'] ?: ($GLOBALS['CFG']['vergelijk_variant'] ?? ''); // onze variant zonder kleur-aanduiding: eventueel de voorkeur uit config.php
    usort($pool, function ($a, $b) use ($kt) { return [$a['kleurtype'] !== $kt, -$a['score'], $a['prijs']] <=> [$b['kleurtype'] !== $kt, -$b['score'], $b['prijs']]; });
    // Bij gelijke kandidaten (bv. 200 RAL-kleurpagina's met dezelfde score) de middelste prijs, niet de goedkoopste: één afwijkende kleur mag de markt niet bepalen
    $top = array_values(array_filter($pool, function ($m) use ($pool, $kt) { return ($m['kleurtype'] !== $kt) === ($pool[0]['kleurtype'] !== $kt) && $m['score'] === $pool[0]['score']; }));
    $g = $top[intdiv(count($top) - 1, 2)]; if ($ons['kleurtype'] !== '' && $g['kleurtype'] !== '' && $g['kleurtype'] !== $ons['kleurtype']) $g['zeker'] = false; // wij wit, zij kleur (of andersom): controleren
    if (prijsgroepen_aan() && preg_match('/^pg/', (string)$ons['kleurtype']) && $g['kleurtype'] === '') $g['zeker'] = false; // onze prijsgroep bekend, hun variant zonder herkende prijsgroep: controleren (28-09-2026)
    return ['gekozen' => $g, 'kand' => $kand, 'door' => 'auto', 'uit' => false];
}
// Onze varianten per blok: per inhoud, en per kleurtype zodra één inhoud meerdere kleurtypes heeft (Weiß en Wunschfarbe
// zijn elk een eigen markt). Sleutel "0.75" of "0.75|kleur"; blokken met één kleurtype houden de oude sleutel, dus je keuzes blijven.
function per_inhoud(array $vs): array {
    $g = []; foreach ($vs as $v) $g[(string)$v['inhoud_std']][] = $v;
    $uit = [];
    foreach ($g as $inh => $groep) {
        if (count(array_unique(array_map(function ($v) { return (string)$v['kleurtype']; }, $groep))) < 2) { $uit[(string)$inh] = $groep; continue; }
        foreach ($groep as $v) $uit[$inh . '|' . ($v['kleurtype'] !== '' ? $v['kleurtype'] : 'overig')][] = $v;
    }
    return $uit;
}
function inhoud_van(string $sleutel): string { return explode('|', $sleutel)[0]; }
// Naam van de referentieshop in alle teksten (neutraal, geen "wij/ons"): de naam van de eigen bron (✎ bij Aanbieders), anders het domein.
function wn(): string { static $n = null; if ($n === null) { $n = ''; try { $n = (string)db()->query('SELECT naam FROM bron WHERE is_eigen=1 LIMIT 1')->fetchColumn(); } catch (Throwable $e) {} if (trim($n) === '') $n = preg_replace('/^www\./', '', site_host()); } return e($n); }
function blok_label(string $sleutel): string { $d = explode('|', $sleutel); return liter($d[0]) . (isset($d[1]) ? ' · ' . kleurtype_label($d[1]) : ''); } // "0,75 L · kleur", "2,5 kg · PG3" (prijsgroepen, 28-09-2026)
function anker(string $sleutel): string { return preg_replace('/[^0-9a-z]/', '', $sleutel); }
// Eigen weergavenaam per product × inhoud (leeg = automatisch)
function weergavenaam(int $pid, string $inh, string $auto): string {
    db()->exec('CREATE TABLE IF NOT EXISTS naam (pid INTEGER, inhoud TEXT, naam TEXT, PRIMARY KEY(pid, inhoud))');
    $q = db()->prepare('SELECT naam FROM naam WHERE pid=? AND inhoud=?'); $q->execute([$pid, $inh]); $n = $q->fetchColumn();
    if ($n === false && $inh !== inhoud_van($inh)) { $q->execute([$pid, inhoud_van($inh)]); $n = $q->fetchColumn(); } // keuze van vóór de splitsing per kleur
    return ($n !== false && trim($n) !== '') ? $n : $auto;
}
// Welke variant van ONS vertegenwoordigt dit product × inhoud? Jouw keuze (tabel eigen_keuze) gaat voor.
// Kandidaten: onze varianten met dezelfde inhoud, eerst van dit product, dan van andere producten.
function kies_eigen(int $pid, string $inh, array $groep, array $eigenRows): array {
    db()->exec('CREATE TABLE IF NOT EXISTS eigen_keuze (pid INTEGER, inhoud TEXT, vid INTEGER, PRIMARY KEY(pid, inhoud))');
    $kand = []; foreach ($groep as $g) $kand[(int)$g['vid']] = $g + ['eigen_product' => true];
    foreach ($eigenRows as $r) if ((string)$r['inhoud_std'] === inhoud_van($inh) && (int)$r['pid'] === $pid && !isset($kand[(int)$r['vid']])) $kand[(int)$r['vid']] = $r + ['eigen_product' => true];
    foreach ($eigenRows as $r) if ((string)$r['inhoud_std'] === inhoud_van($inh) && !isset($kand[(int)$r['vid']])) $kand[(int)$r['vid']] = $r + ['eigen_product' => false];
    $q = db()->prepare('SELECT vid FROM eigen_keuze WHERE pid=? AND inhoud=?'); $q->execute([$pid, $inh]); $k = $q->fetchColumn();
    if ($k === false && $inh !== inhoud_van($inh)) { $q->execute([$pid, inhoud_van($inh)]); $k = $q->fetchColumn(); if ($k !== false && !isset(array_column($groep, null, 'vid')[(int)$k])) $k = false; } // oude keuze geldt alleen in het blok van die variant
    if ($k !== false && isset($kand[(int)$k])) return ['ons' => $kand[(int)$k], 'kand' => $kand, 'door' => 'jij'];
    // Automatisch: de variant die wij het meest verkopen (omzet uit Lightspeed, zie omzet_periode()). Zonder omzetdata:
    // 'vergelijk_variant' uit config.php als die gezet is, anders de eerste.
    $om = omzet_per_variant(); $best = null;
    foreach ($groep as $g) { $o = (float)($om[(string)$g['vext']] ?? 0); if ($o > 0 && ($best === null || $o > $best[1])) $best = [$g, $o]; }
    if ($best) return ['ons' => $best[0], 'kand' => $kand, 'door' => 'auto'];
    $vk = $GLOBALS['CFG']['vergelijk_variant'] ?? '';
    foreach ($groep as $g) if ($vk !== '' && $g['kleurtype'] === $vk) return ['ons' => $g, 'kand' => $kand, 'door' => 'auto'];
    return ['ons' => $groep[0], 'kand' => $kand, 'door' => 'auto'];
}
// De markt rond één variant van ons: gekozen concurrent per bron, gesorteerde prijzen, mediaan, positie.
function markt_variant(array $ons, array $concRows, array $conc): array {
    $perBron = []; $keuzes = [];
    foreach ($conc as $cb) { $k = kies_voor_bron($ons, $concRows, (int)$cb['id']); if (!$k['kand']) continue; $keuzes[(int)$cb['id']] = $k + ['naam' => $cb['naam']]; if ($k['gekozen']) $perBron[(int)$cb['id']] = $k['gekozen'] + ['bnaam' => $cb['naam']]; }
    $p = array_map(function ($c) { return (float)$c['prijs']; }, array_values($perBron)); sort($p);
    $n = count($p); $med = $n ? ($n % 2 ? $p[intdiv($n, 2)] : ($p[$n / 2 - 1] + $p[$n / 2]) / 2) : null; $wij = (float)$ons['prijs'];
    $pos = 1 + count(array_filter($p, function ($x) use ($wij) { return $x < $wij - 0.005; }));
    $gedeeld = count(array_filter($p, function ($x) use ($wij) { return abs($x - $wij) <= 0.005; })) > 0;
    $laagste = null; foreach ($perBron as $c) if ($laagste === null || $c['prijs'] < $laagste['prijs']) $laagste = $c;
    return ['ons' => $ons, 'perBron' => $perBron, 'keuzes' => $keuzes, 'p' => $p, 'n' => $n, 'med' => $med, 'pos' => $pos, 'gedeeld' => $gedeeld, 'laagste' => $laagste,
            'vsmed' => $n ? ($wij - $med) / $med * 100 : null];
}
// Alle varianten van ons in de radar, gegroepeerd per product en inhoud, met hun markt
function markt_alles(array $eigenRows, array $concRows, array $conc): array {
    $prods = []; foreach ($eigenRows as $r) if ($r['inhoud_l'] !== null) $prods[$r['pid']][] = $r;
    $uit = [];
    foreach ($prods as $pid => $vs) foreach (per_inhoud($vs) as $inh => $groep) {
        $ke = kies_eigen((int)$pid, (string)$inh, $groep, $eigenRows);
        $m = markt_variant($ke['ons'], $concRows, $conc);
        if ($ke['door'] === 'jij') $groep = [$ke['ons']];
        $m['naam'] = weergavenaam((int)$pid, (string)$inh, implode(' / ', array_unique(array_map(function ($g) { return volle_naam($g['ptitel'], $g['vtitel']); }, array_merge([$ke['ons']], $groep))))); // vergeleken variant eerst
        $m['pid'] = $pid; $m['sleutel'] = (string)$inh; $uit[$pid][] = $m;
    }
    return $uit;
}
function positie_tekst(array $m): string { return $m['n'] ? ($m['gedeeld'] ? 'gedeeld ' : '') . $m['pos'] . 'e van ' . ($m['n'] + 1) : 'geen markt'; }
// mini spreidingsbalk: stippen = concurrenten, streep = mediaan, blauw = wij
function mini_band(array $m): string {
    if (!$m['n']) return '<span class="muted" style="font-size:12px">geen concurrent</span>';
    $wij = (float)$m['ons']['prijs']; $lo = min($m['p'][0], $wij); $hi = max($m['p'][$m['n'] - 1], $wij);
    if ($hi - $lo <= 0.005) return '<span class="muted" style="font-size:12px">iedereen gelijk</span>';
    $x = function ($v) use ($lo, $hi) { return round(($v - $lo) / ($hi - $lo) * 100, 1); };
    $h = '<div class="mb"><div class="sv-lijn" style="top:8px"></div>';
    foreach ($m['p'] as $pv) $h .= '<span class="sv-stip" style="left:' . $x($pv) . '%;top:3px;border-color:#fff"></span>';
    return $h . '<span class="sv-wij" style="left:' . $x($wij) . '%;top:1px"></span><span class="sv-med" style="left:' . $x($m['med']) . '%;top:-3px;height:24px" title="Mediaan"></span></div>';
}
function korte_titel($t) { $t = trim(explode('|', $t)[0]); return preg_replace('/^(\w+)\s+\1\b/iu', '$1', $t); }
// alle varianten met laatste prijs, per bron
// Tijdmachine voor trendlijnen: met $GLOBALS['TOT'] gezet kijken aanbod() en alle_rijen() naar de stand op dat moment.
function tot_sql(): string { return empty($GLOBALS['TOT']) ? '' : " AND tijd <= " . db()->quote($GLOBALS['TOT']); }
// Per week: onze afwijking van de mediaan (gemiddeld) en per concurrent het gemiddelde verschil met ons.
function trend(array $eigen, array $conc, string $land): array {
    $weken = db()->query("SELECT strftime('%Y-%W', m.tijd) wk, MAX(m.tijd) t FROM meting m JOIN variant v ON v.id=m.variant_id JOIN product p ON p.id=v.product_id JOIN bron b ON b.id=p.bron_id WHERE b.is_eigen=0 GROUP BY wk ORDER BY wk DESC LIMIT 26")->fetchAll();
    $uit = [];
    foreach (array_reverse($weken) as $w) {
        $GLOBALS['TOT'] = $w['t'];
        $cr = array_filter(alle_rijen(), function ($r) use ($land) { return !$r['is_eigen'] && $r['land'] === $land; });
        $MA = markt_alles(aanbod((int)$eigen['id']), $cr, $conc);
        $vm = []; $pc = [];
        foreach ($MA as $ms) foreach ($ms as $m) { if ($m['n']) $vm[] = $m['vsmed']; foreach ($m['perBron'] as $bid => $c) $pc[$bid][] = ($c['prijs'] - $m['ons']['prijs']) / $m['ons']['prijs'] * 100; }
        $uit[] = ['datum' => substr($w['t'], 0, 10), 'wij' => $vm ? array_sum($vm) / count($vm) : null, 'n' => count($vm), 'conc' => array_map(function ($a) { return array_sum($a) / count($a); }, $pc)];
    }
    unset($GLOBALS['TOT']);
    return $uit;
}
function trend_svg(array $tr, array $namen): string {
    $W = 600; $H = 230; $l = 44; $r = 120; $t = 14; $b = 30; $n = count($tr);
    $alle = []; foreach ($tr as $p) { if ($p['wij'] !== null) $alle[] = $p['wij']; foreach ($p['conc'] as $v) $alle[] = $v; }
    $m = max(5, ceil(max(array_map('abs', $alle ?: [0])) / 5) * 5);
    $X = function ($i) use ($n, $l, $r, $W) { return $n < 2 ? ($l + ($W - $l - $r) / 2) : $l + $i * ($W - $l - $r) / ($n - 1); };
    $Y = function ($v) use ($m, $t, $b, $H) { return $t + ($m - $v) / (2 * $m) * ($H - $t - $b); };
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" role="img" aria-label="Prijsverloop" style="display:block">';
    foreach ([-$m, -$m / 2, 0, $m / 2, $m] as $v) { $y = $Y($v); $h .= '<line x1="' . $l . '" x2="' . ($W - $r) . '" y1="' . $y . '" y2="' . $y . '" stroke="' . ($v == 0 ? '#9a978f' : '#e4e2dc') . '"/><text x="' . ($l - 6) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="10" fill="#5b6068">' . ($v > 0 ? '+' : '') . $v . '%</text>'; }
    foreach ($tr as $i => $p) if ($i === 0 || $i === $n - 1 || $n <= 6 || $i % 3 === 0) $h .= '<text x="' . $X($i) . '" y="' . ($H - 10) . '" text-anchor="middle" font-size="10" fill="#5b6068">' . date('d-m', strtotime($p['datum'])) . '</text>';
    $lijn = function ($pts, $kl, $w, $label) use (&$h, $X, $Y, $W, $r) {
        $p2 = array_filter($pts, function ($v) { return $v !== null; }); if (!$p2) return;
        $d = ''; foreach ($p2 as $i => $v) $d .= ($d === '' ? 'M' : 'L') . round($X($i), 1) . ' ' . round($Y($v), 1) . ' ';
        $h .= '<path d="' . $d . '" fill="none" stroke="' . $kl . '" stroke-width="' . $w . '"/>';
        foreach ($p2 as $i => $v) $h .= '<circle cx="' . round($X($i), 1) . '" cy="' . round($Y($v), 1) . '" r="' . ($w + 1) . '" fill="' . $kl . '"><title>' . e($label) . ': ' . number_format($v, 1, ',', '') . '%</title></circle>';
        $li = array_key_last($p2); $GLOBALS['TR_LAB'][] = [$Y($p2[$li]) + 4, $kl, mb_substr($label, 0, 16)];
    };
    $GLOBALS['TR_LAB'] = [];
    $bids = []; foreach ($tr as $p) foreach ($p['conc'] as $bid => $v) $bids[$bid] = 1;
    foreach (array_keys($bids) as $bid) $lijn(array_map(function ($p) use ($bid) { return $p['conc'][$bid] ?? null; }, $tr), '#b9bcc2', 1.5, $namen[$bid] ?? '?');
    $lijn(array_map(function ($p) { return $p['wij']; }, $tr), '#1a1c1e', 2.5, html_entity_decode(wn()) . ' vs mediaan');
    $lab = $GLOBALS['TR_LAB']; usort($lab, function ($x, $y) { return $x[0] <=> $y[0]; }); $vorig = -99;
    foreach ($lab as $x) { $y = max($x[0], $vorig + 13); $vorig = $y; $h .= '<text x="' . ($W - $r + 8) . '" y="' . round($y, 1) . '" font-size="11" fill="' . ($x[1] === '#b9bcc2' ? '#8a8f96' : $x[1]) . '">' . e($x[2]) . '</text>'; }
    return $h . '</svg>';
}
function alle_rijen(): array {
    return kleur_nu(db()->query("SELECT b.id bid, b.naam, b.is_eigen, b.land, p.titel ptitel, v.id vid, v.titel vtitel, v.inhoud_l, v.ean, v.inhoud_std, v.kleurtype, m.prijs, m.van_prijs, m.op_voorraad
        FROM bron b JOIN product p ON p.bron_id=b.id JOIN variant v ON v.product_id=p.id
        JOIN meting m ON m.id=(SELECT id FROM meting WHERE variant_id=v.id" . tot_sql() . " ORDER BY tijd DESC, id DESC LIMIT 1)
        WHERE " . IN_RADAR . "=1")->fetchAll());
}
// Koppel een variant aan varianten in een andere set (onze shop ↔ een aanbieder).
// 1. Zelfde EAN/GTIN: zeker. 2. Zelfde inhoud + zelfde productwoorden (naam_score 3) of op een tikfout na (2): automatisch.
// 3. Zelfde inhoud + één naam met extra woorden (1): alleen als voorstel, nooit automatisch.
// Wederzijds beste match: past het product van de aanbieder beter bij een ánder product van ons, dan hoort het daar.
function koppel(array $v, array $kandidaten, bool $metVoorstel = false): array {
    $ev = ean_norm($v['ean'] ?? '');
    if ($ev !== '') { $uit = []; foreach ($kandidaten as $c) if (ean_norm($c['ean'] ?? '') === $ev) $uit[] = $c + ['soort' => 'EAN', 'zeker' => true, 'auto' => true, 'score' => 4];
        if ($uit) return $uit; }
    if ($v['inhoud_std'] === null) return [];
    $wv = naam_woorden($v['ptitel']); $uit = [];
    foreach ($kandidaten as $c) {
        if ((string)$c['inhoud_std'] !== (string)$v['inhoud_std']) continue;
        $wc = naam_woorden($c['ptitel']); $sc = naam_score($wv, $wc); if (!$sc) continue;
        $hun = !empty($v['is_eigen']) ? $wc : $wv; $ons = !empty($v['is_eigen']) ? $wv : $wc;
        foreach (eigen_namen((string)$v['inhoud_std']) as $ander) if ($ander !== $ons) { $sa = naam_score($ander, $hun);
            if ($sa > $sc) continue 2;             // hoort bij een ander product van ons
            if ($sa === $sc && $sc < 3) $sc = 1; } // past even goed bij twee van onze producten: alleen voorstel
        if ($sc >= 2 || $metVoorstel) $uit[] = $c + ['soort' => 'naam + inhoud', 'zeker' => $sc === 3, 'auto' => $sc >= 2, 'score' => $sc];
    }
    usort($uit, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return $uit;
}
// Productwoorden van onze producten met deze inhoud (voor de wederzijds-beste-match-controle; een kleurenfolder telt dus niet mee)
function eigen_namen(string $inhoud): array {
    static $n = null;
    if ($n === null) { $n = [];
        foreach (db()->query('SELECT DISTINCT p.titel, v.inhoud_std FROM product p JOIN bron b ON b.id=p.bron_id JOIN variant v ON v.product_id=p.id WHERE b.is_eigen=1 AND v.inhoud_std IS NOT NULL')->fetchAll() as $r)
            $n[(string)$r['inhoud_std']][naamsleutel($r['titel'])] = naam_woorden($r['titel']); }
    return array_values($n[$inhoud] ?? []);
}

// ---------- CSV-export ----------
if (isset($_GET['csv']) && !empty($_GET['bron'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="aanbod-bron-' . (int)$_GET['bron'] . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, ['product', 'variant', 'inhoud_l', 'inhoud_vergelijk', 'prijs_incl_btw', 'ean', 'sku', 'leverbaar', 'levering', 'gemeten', 'url'], ';');
    foreach (aanbod((int)$_GET['bron']) as $r) fputcsv($o, [$r['ptitel'], $r['vtitel'], $r['inhoud_l'], $r['inhoud_std'], $r['prijs'], $r['ean'], $r['sku'], $r['op_voorraad'], $r['levertijd'], $r['tijd'], $r['url']], ';');
    exit;
}

if (api_ingesteld()) {
    $h = preg_replace('#^https?://#', '', rtrim($CFG['site'], '/'));
    db()->prepare("UPDATE bron SET platform='lightspeed', domein=?, listing_url=? WHERE is_eigen=1")->execute([$h, 'https://' . $h . '/ (API)']);
}
$tab = $_GET['tab'] ?? 'overzicht'; if ($tab === 'toevoegen') $tab = 'concurrenten';
$alle = bronnen();
$eigen = null; foreach ($alle as $b) if ($b['is_eigen']) { $eigen = $b; break; }
$concAlle = array_values(array_filter($alle, function ($b) { return !$b['is_eigen']; }));
$conc = array_values(array_filter($concAlle, function ($b) use ($LAND) { return $b['land'] === $LAND; }));
$alle = array_values(array_filter($alle, function ($b) use ($LAND) { return $b['is_eigen'] || $b['land'] === $LAND; }));
$tabs = ['overzicht' => 'Overzicht', 'grafieken' => 'Grafieken', 'producten' => 'Producten', 'shop' => wn(), 'concurrenten' => 'Aanbieders'];
$laatst = db()->query('SELECT MAX(eind) FROM run')->fetchColumn();
// Wekelijkse update: is iets ouder dan 'update_dagen' (standaard 7), dan start bij de eerste pagina van een sessie een update van alles
if ($WEEKCHECK) {
    if (!open_runs() && bronnen()) {
        $oud = db()->query("SELECT COUNT(*) FROM bron b WHERE COALESCE((SELECT MAX(eind) FROM run r WHERE r.bron_id=b.id), '2000-01-01') < '" . date('Y-m-d H:i:s', strtotime('-' . drempel('update_dagen') . ' days')) . "'")->fetchColumn();
        if ($oud) { foreach (bronnen() as $bb) run_start((int)$bb['id']); $melding = ['ok' => true, 'tekst' => 'Wekelijkse update gestart: alle aanbieders worden bijgewerkt.']; }
    }
}
$OPEN = []; foreach (open_runs() as $or) $OPEN[(int)$or['bron_id']] = $or;
omzet_db(); $ORUN = db()->query('SELECT id FROM omzet_run WHERE eind IS NULL LIMIT 1')->fetchColumn();
?><!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Prijsradar · <?= e($CFG['site']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--ink:#1a1c1e;--muted:#5b6068;--line:#e4e2dc;--bg:#f5f4ef;--card:#fff;--ours:#1f4fd1;--ok:#1d6a3d;--warn:#9b1c1c;--prem:#8e3009}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 'Geist','Helvetica Neue',sans-serif}
a{color:var(--ours);text-decoration:none}a:hover{color:#163a9c}
.top{background:var(--card);border-bottom:1px solid var(--line);padding:0 40px}
.top .bar{display:flex;align-items:center;justify-content:space-between;padding:16px 0 4px;gap:16px}
.brand{display:flex;align-items:baseline;gap:10px}.brand b,.brand span.site{font-size:22px;letter-spacing:-.02em}.brand span.site{font-weight:500;color:var(--muted)}.meta{font-size:13px;color:var(--muted)}
nav{display:flex;gap:28px}nav a{padding:13px 2px;font-weight:500;color:var(--muted);border-bottom:2px solid transparent}
nav a.on{color:var(--ink);font-weight:600;border-color:var(--ink)}
main{padding:28px 40px 40px;max-width:1440px}
h1{font-size:28px;font-weight:650;letter-spacing:-.02em;margin:0 0 6px}h2{font-size:18px;font-weight:600;margin:0}h3{font-size:16px;font-weight:600;margin:0}
.muted{color:var(--muted)}.lead{color:var(--muted);margin:0 0 22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:16px}
.card.flush{padding:0;overflow:hidden}.card.flush .head{padding:18px 20px 8px;display:flex;justify-content:space-between;align-items:baseline;gap:12px}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:18px}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 20px}.kpi small{font-size:13px;color:var(--muted);font-weight:500}
.kpi div{font-size:30px;font-weight:600;letter-spacing:-.02em;margin:4px 0 2px}.kpi p{margin:0;font-size:13px;color:var(--muted)}
table{width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed}th:first-child,td:first-child{width:34%}.samenvatting{background:#f7f6f1;padding:22px 24px 18px;border-bottom:1px solid var(--line)}.sv-grid{display:grid;grid-template-columns:1.4fr repeat(4,minmax(0,1fr));gap:18px;align-items:end}.sv-grid small{display:block;font-size:12px;color:var(--muted);font-weight:500;margin-bottom:4px}.sv-grid b{font-size:18px}.sv-hoofd b{font-size:18px}.sv-sub{font-size:13px;color:var(--muted);margin-top:4px;white-space:nowrap}.sv-band{position:relative;height:22px;margin:8px 8px 4px}.sv-lijn{position:absolute;left:0;right:0;top:10px;height:2px;background:#d6d4cd;border-radius:2px}.sv-stip{position:absolute;top:5px;width:12px;height:12px;margin-left:-6px;border-radius:99px;background:#9aa0a6;border:2px solid #f7f6f1}.sv-med{position:absolute;top:0;width:2px;height:22px;margin-left:-1px;background:var(--prem)}.sv-wij{position:absolute;top:3px;width:16px;height:16px;margin-left:-8px;border-radius:99px;background:var(--ours);border:3px solid #fff;box-shadow:0 0 0 1px var(--ours)}.sv-legenda{display:flex;gap:16px;align-items:center;font-size:12px;color:var(--muted);margin-top:18px}.sv-legenda i{display:inline-block;vertical-align:-2px;margin-right:6px}.lg-wij{width:12px;height:12px;border-radius:99px;background:var(--ours)}.lg-stip{width:10px;height:10px;border-radius:99px;background:#9aa0a6}.lg-med{width:2px;height:12px;background:var(--prem)}.sv-as{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin:0 8px}.rk-kop,.rk-rij{display:grid;grid-template-columns:34px minmax(0,1fr) 130px 90px 100px 140px;gap:14px;align-items:center;padding:10px 20px}.rk-kop{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding-top:14px}.rk-rij{border-top:1px solid var(--line)}.rk-rij.wij{background:#eef2fc}.rk-det>summary.rk-rij.wij:hover{background:#e6ecfb}.rk-rij.wij b{color:var(--ours)}.vnaam{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rk-det{border-top:1px solid var(--line)}.rk-det>summary{list-style:none;cursor:pointer;border-top:0}.rk-det>summary::-webkit-details-marker{display:none}.rk-det>summary:hover{background:#fbfaf7}
.naamform{display:none;align-items:center;gap:8px}.naamvak.bewerk h2{display:none}.naamvak.bewerk .naamform{display:flex}.edit{border:0;background:none;color:var(--muted);font-size:14px;cursor:pointer;padding:2px 6px;border-radius:6px;vertical-align:2px}.edit:hover{background:#ecebe6;color:var(--ink)}.sorteer-kop{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:6px 0 4px;flex-wrap:wrap}.sorteer{display:flex;align-items:center;gap:4px;font-size:13px}.sorteer .muted{margin-right:6px}.sorteer button{font:inherit;font-size:13px;padding:6px 12px;border-radius:99px;border:1px solid var(--line);background:#fff;cursor:pointer;color:var(--ink)}.sorteer button.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.omzetregel{font-size:13px;margin:0 0 12px}.linkknop{border:0;background:none;color:var(--ours);font:inherit;font-weight:600;cursor:pointer;padding:0}.pomzet{font-size:13px;margin-right:10px}.pmarge{font-size:13px;margin-right:4px;white-space:nowrap}
.bnaam{font-size:15px;font-weight:600;margin:0;display:inline}.bnaam a{color:var(--ours)}.vblok{border-color:#b9b5ab}.card.flush.vblok{border-width:1.5px}
.rk-rij.uit b{color:#a8adb3}.wijzig{color:var(--ours);font-size:12px;margin-left:6px}.rk-det[open] .wijzig{visibility:hidden}
.kies{background:#fbfaf7;padding:6px 20px 14px 68px;border-top:1px dashed var(--line)}.kies-kop{font-size:13px;color:var(--muted);margin:8px 0}
.kies-optie{display:flex;flex-direction:row;align-items:center;gap:12px;padding:8px 10px;border-radius:8px;font-size:14px;font-weight:400;cursor:pointer}.kies-optie:hover{background:#f0efe9}.kies-optie input{width:auto}table.lijst{table-layout:auto}.runbalk{min-width:220px}.rb-tekst{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:#163a9c;margin-bottom:5px;white-space:normal}.rb-bak{height:6px;background:#e6ecfb;border-radius:99px;overflow:hidden}.rb-vul{height:100%;background:var(--ours);border-radius:99px;transition:width .6s}
.hdr-run{font-size:12px;color:#163a9c;background:#e6ecfb;padding:4px 10px;border-radius:99px}table.lijst th,table.lijst td{white-space:nowrap}table.lijst td:first-child,table.lijst th:first-child{width:auto;white-space:normal}td{overflow-wrap:anywhere}th{text-align:left;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:10px 14px}
td{padding:10px 14px;border-top:1px solid var(--line);vertical-align:middle}
.num{font-family:'Geist Mono',ui-monospace,monospace;font-size:13.5px;white-space:nowrap}.num.groen{color:#1d6a3d;font-weight:600}.num.rood{color:#b42318;font-weight:600}.num.ours{color:var(--ours);font-weight:600}
.chip{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#ecebe6;color:#3f4650;white-space:nowrap}
.chip.ok{background:#e3f1e8;color:var(--ok)}.chip.warn{background:#fbe4e4;color:var(--warn)}.chip.acc{background:#e6ecfb;color:#163a9c}.chip.amb{background:#fcefd6;color:#7a4e00}
.btn{display:inline-block;font:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer}
.btn.dark{background:var(--ink);color:#fff;border-color:var(--ink)}.btn.dark:hover{color:#fff}form.inline{display:inline}
.split{display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start}
.clist{display:flex;flex-direction:column;gap:10px}
.citem{display:flex;flex-direction:column;gap:8px;padding:14px 16px;border:1px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink)}
.citem.on{border:2px solid var(--ink);background:#fbfaf7}.citem .r{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12px;color:var(--muted)}
.pchips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}.pchips a{font-size:14px;font-weight:500;padding:8px 15px;border-radius:999px;border:1px solid var(--line);background:var(--card);color:var(--ink)}
.pchips a.on{background:var(--ink);color:#fff;border-color:var(--ink);font-weight:600}
.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px;padding-top:14px;border-top:1px solid var(--line);margin-top:14px}
.stats small{display:block;font-size:12px;color:var(--muted);font-weight:500}.stats b{font-size:15px}
.melding{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;background:#e3f1e8;color:var(--ok)}.melding.fout{background:#fbe4e4;color:var(--warn)}
.later{border:1px dashed #cfccc3;border-radius:14px;padding:16px 20px;color:var(--muted);font-size:14px;margin-bottom:16px}
.veld{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600}.veld .uitleg{font-size:12.5px;font-weight:400;color:var(--muted);line-height:1.4}
input,select{font:inherit;font-size:15px;font-weight:400;padding:10px 13px;border:1px solid var(--line);border-radius:10px;background:#fff}
.stepnr{width:28px;height:28px;border-radius:99px;background:var(--ink);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
.voet{font-size:12px;color:var(--muted);margin-top:26px}
.ov-kop,.ov-rij{display:grid;grid-template-columns:minmax(0,1fr) 100px 190px 100px 110px 130px 170px;gap:14px;align-items:center;padding:10px 20px}.ov-kop{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding-top:12px}.ov-rij{border-top:1px solid var(--line);color:var(--ink)}.ov-rij:hover{background:#fbfaf7;color:var(--ink)}.mb{position:relative;height:18px;margin:0 8px}@media(min-width:701px){.gr-rij{grid-template-columns:minmax(0,1.3fr) 1fr 80px}}.gr-rij .vnaam{white-space:normal;overflow:visible;text-overflow:clip;line-height:1.35}.sv-med{z-index:2;box-shadow:0 0 0 1px #fff}
.gr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.gr-grid .card{margin:0}.gr-uitleg{font-size:12.5px;color:var(--muted);margin:4px 0 14px}.gr-rij{display:grid;grid-template-columns:minmax(0,210px) 1fr 80px;gap:12px;align-items:center;padding:5px 0;font-size:13px;color:var(--ink)}.gr-rij:hover{color:var(--ink);background:#fbfaf7}.gr-bak{position:relative;height:14px}.gr-nul{position:absolute;left:50%;top:-3px;bottom:-3px;width:1px;background:#c9c6bd}.gr-staaf{position:absolute;top:0;height:14px;border-radius:3px}.gr-stapel{display:flex;height:22px;border-radius:6px;overflow:hidden;gap:2px}.gr-stapel span{display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600;min-width:22px}.gr-leeg{border:1px dashed #cfccc3;border-radius:12px;padding:40px 20px;text-align:center;display:flex;flex-direction:column;gap:6px;color:var(--muted);font-size:13.5px}.gr-leeg b{color:var(--ink);font-size:15px}
.gr-kop{display:flex;justify-content:space-between;align-items:baseline;gap:12px}.gr-open{border:0;background:none;color:var(--ours);font:inherit;font-size:13px;font-weight:600;cursor:pointer;padding:0}
.pop{border:0;border-radius:16px;padding:0;width:min(1100px,94vw);max-height:86vh;box-shadow:0 24px 60px rgba(0,0,0,.25)}.pop::backdrop{background:rgba(26,28,30,.35)}
.pop-kop{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 22px;border-bottom:1px solid var(--line);position:sticky;top:0;background:#fff}.pop-body{padding:4px 22px 22px;overflow:auto;max-height:calc(86vh - 70px)}.pop tr:hover td{background:#fbfaf7}
@media(max-width:1100px){.gr-grid{grid-template-columns:1fr}.ov-kop{display:none}.ov-rij{grid-template-columns:1fr 1fr}}
@media(max-width:700px){
html,body{overflow-x:hidden}
.top{padding:0 16px}.top .bar{flex-wrap:wrap;gap:8px;padding:12px 0 2px}.brand b,.brand span.site{font-size:19px}.meta>span:not(.hdr-run){display:none}
nav{overflow-x:auto;gap:18px;white-space:nowrap;scrollbar-width:none}nav::-webkit-scrollbar{display:none}nav a{padding:11px 0;flex:none}
main{padding:18px 16px 32px}h1{font-size:23px}.card{padding:16px}
.card.flush{overflow-x:auto}.samenvatting{padding:16px}
.samenvatting>div:first-child{flex-direction:column;align-items:flex-start!important;gap:8px!important}.samenvatting .chip{white-space:normal}
.naamvak h2{font-size:18px!important}.naamform{flex-wrap:wrap}.naamform input{width:100%!important}
.sv-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.sv-hoofd{grid-column:1/-1}.sv-sub{white-space:normal}.sv-legenda{flex-wrap:wrap;gap:8px 14px}
.rk-kop{display:none}.rk-rij{display:flex;flex-wrap:wrap;gap:4px 12px;padding:12px 16px}.rk-rij>span:first-child{width:18px}.rk-rij>div{flex:1 1 calc(100% - 40px)}.rk-rij>span:not(:first-child){margin-left:30px}.rk-rij>span:nth-child(n+4){margin-left:0}
.rk-rij .vnaam{white-space:normal}.kies{padding:6px 12px 12px}
.ov-rij{grid-template-columns:1fr auto;gap:4px 12px}.ov-rij>span:nth-child(n+3){font-size:13px}.ov-rij .vnaam{white-space:normal;grid-column:1/-1}
.gr-rij{grid-template-columns:minmax(0,1fr) 34% 64px}.gr-rij .vnaam{white-space:normal}
.vtab th:first-child,.vtab td:first-child{padding-left:12px}.prow{gap:10px;padding:12px}.pomzet{display:none}
.sorteer{flex-wrap:wrap}.pchips{gap:6px}.pchips a{font-size:13px;padding:6px 12px}
table.lijst{font-size:13px}.kpi div{font-size:24px}
form.card[style*="grid-template-columns"]{grid-template-columns:1fr!important}form.card[style*="grid-template-columns"]>div{padding-bottom:0!important}
table.lijst td:first-child{min-width:170px}table.lijst td{overflow-wrap:normal}
.card>div[style*="justify-content:space-between"]{flex-wrap:wrap}.card h1{overflow-wrap:anywhere}
.card table td[style*="width:220px"]{width:38%!important;overflow-wrap:normal}.card table td[style*="width:90px"]{width:44px!important}
.card table td{overflow-wrap:break-word}
}
@media(max-width:900px){.split{grid-template-columns:1fr}.kpis,.stats{grid-template-columns:repeat(2,minmax(0,1fr))}main,.top{padding-left:16px;padding-right:16px}}
.waarom{margin-top:6px;font-size:12px;color:var(--muted,#6e6e73);white-space:normal;font-variant-numeric:normal;max-width:420px}
.wacht{background:#1d1d1f;color:#fff;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;display:flex;gap:12px;align-items:center}
.wacht b{font-weight:600}.wacht i{width:14px;height:14px;border:2px solid #fff;border-right-color:transparent;border-radius:50%;flex:none;animation:dr 1s linear infinite}@keyframes dr{to{transform:rotate(360deg)}}
body.bezig main form:is(:has(input[name=actie][value=inventaris]),:has(input[name=actie][value=alles]),:has(input[name=actie][value=toevoegen]),:has(input[name=actie][value=omzet]),:has(input[name=actie][value=verwijderen]),:has(input[name=actie][value=basis])) :is(button,input,select){pointer-events:none;opacity:.45}
table.lijst td:last-child,table.lijst th:last-child{padding-right:20px}table.lijst td.bijgew{white-space:normal;min-width:170px}.runbalk{min-width:160px}.rb-tekst b{white-space:nowrap}table.lijst td:first-child{min-width:190px}table.lijst .bnaam a{word-break:normal;overflow-wrap:normal}.card.flush:has(table.lijst){overflow-x:auto}
.naamform button{white-space:nowrap;flex:none;padding:7px 14px;font-size:13px;line-height:1.2}.naamform input{min-width:0}
.mk-p{font-size:13px;font-weight:600;margin:12px 0 2px;color:var(--ink)}.mk-p:first-child{margin-top:0}.mk-rij{display:grid;grid-template-columns:110px 1fr 64px;gap:12px;align-items:center;padding:2px 0;font-size:12.5px;color:var(--ink)}.mk-rij:hover{background:#f7f6f1}.mk-zone{position:absolute;top:-3px;bottom:-3px;background:#eeede8}.mk{columns:2;column-gap:40px}.mk-p,.mk-rij{break-inside:avoid}
.mk-g{break-inside:avoid;padding-bottom:2px}.samenvatting .sv-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;align-items:start}.pb-k small{display:block;font-size:12px;color:var(--muted);font-weight:500;margin-bottom:4px;white-space:nowrap}.pb-k b{display:block;font-size:18px;font-weight:600;white-space:nowrap}.pb-ons b{color:var(--ours);font-weight:700}.pb-k .sv-sub{min-height:18px;white-space:normal}@media(max-width:700px){.samenvatting .sv-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}.sp-rij{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr) 44px;gap:12px;align-items:center;padding:6px 0;font-size:13px;color:var(--ink)}.sp-balk{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center;font-size:12px}.sp-bak{position:relative;height:14px;margin:0 7px}.sp-lijn{position:absolute;left:0;right:0;top:6px;height:3px;border-radius:2px;background:#d9d6cc}.sp-bak .sp-wij{top:1px}.dk-rij{display:flex;align-items:center;justify-content:space-between;gap:16px}.dk-rij>span{flex:1;min-width:0}.dk-rij form{flex:none}@media(max-width:700px){.dk-rij{flex-wrap:wrap}}.dk-rij .runbalk{flex:1 1 100%}details.dr-g{border-top:1px solid var(--line);padding:9px 0}details.dr-g summary{display:grid;grid-template-columns:12px 1fr auto;column-gap:10px;row-gap:2px;align-items:center;cursor:pointer;list-style:none;font-size:14px}details.dr-g summary::-webkit-details-marker{display:none}details.dr-g summary i{width:12px;height:12px;border-radius:3px}details.dr-g summary small{grid-column:2/4;color:var(--muted);font-size:12.5px}details.dr-g summary .num{font-size:13px;color:var(--muted)}details.dr-g ul{margin:8px 0 2px 22px}.bodem-regel{font-size:12.5px;color:var(--muted);margin:6px 8px 0}.pb-mg{font-size:12.5px;color:var(--ok);margin-top:2px;font-weight:600}.pb-mg.laag{color:#b42318}.sv-bodem{position:absolute;top:-6px;height:34px;border-left:2px dashed #b42318;margin-left:-1px}.sv-bodem i{position:absolute;top:34px;left:-18px;font-size:10.5px;font-style:normal;color:#b42318;white-space:nowrap}.lg-bodem{width:0;height:12px;border-left:2px dashed #b42318}.mx-leg{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 28px;margin-top:14px}.mx-leg h4{font-size:13px;margin:10px 0 6px}.mx-leg ul{list-style:none;margin:0;padding:0}.mx-leg li.mx-kop{font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);border-top:0}.mx-leg li{display:grid;grid-template-columns:24px 1fr 78px 78px;gap:8px;align-items:center;font-size:13px;padding:3px 0;border-top:1px solid var(--line)}.mx-nr{display:inline-flex;width:20px;height:20px;border-radius:99px;color:#fff;font-size:11px;align-items:center;justify-content:center}.bv-naam{white-space:normal;line-height:1.3}.bv-rij{display:grid;grid-template-columns:minmax(0,1.4fr) 1fr 84px 76px;gap:12px;align-items:center;padding:5px 0;font-size:13px;color:var(--ink)}.bv-kop{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);border-bottom:1px solid var(--line)}@media(max-width:700px){.mx-leg{grid-template-columns:1fr}}.mb-leg{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12.5px;color:var(--muted);margin:0 0 8px}.mb-leg span{display:inline-flex;align-items:center;gap:6px}.mb-leg i{display:inline-block;width:10px;height:10px;border-radius:99px}.start ol{list-style:none;margin:10px 0 0;padding:0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.start li{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--line);border-radius:10px;padding:10px 12px}.start li b{flex:none;width:24px;height:24px;border-radius:99px;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px}.start li.ok b{background:#2e9e4f}.start li a{font-weight:600;font-size:14px}.start li small{display:block;color:var(--muted);font-size:12px;margin-top:2px}@media(max-width:900px){.start ol{grid-template-columns:1fr 1fr}}@media(max-width:600px){.start ol{grid-template-columns:1fr}}.minmarge{display:flex;align-items:center;gap:6px;flex:none;font-size:13px}.minmarge label{display:flex;align-items:center;gap:6px;white-space:nowrap}.mini-knop{font:inherit;font-size:11.5px;font-weight:600;padding:2px 9px;border-radius:99px;border:1px solid var(--line);background:#fff;cursor:pointer;color:var(--ink);margin-left:4px}.mini-knop.ok{border-color:#9fd0ad;color:#1d6a3d}.mini-knop:hover{background:#f3f2ed}.match-op{font-size:12px;color:var(--muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.match-op b{font-weight:600;color:#3f4650}.gr-sectie{font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:26px 0 10px}.dr{display:flex;align-items:flex-end;gap:14px;height:150px;padding-top:6px}.dr-k{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;font-size:12px}.dr-k i{display:block;width:100%;max-width:56px;border-radius:5px 5px 0 0}.dr-k small{color:var(--muted);white-space:nowrap}.sp-wij{position:absolute;top:1px;width:12px;height:12px;margin-left:-6px;border-radius:99px;background:#1f4fd1;border:2px solid #fff}ul.kl{margin:0;padding-left:18px;font-size:13px}ul.kl li{margin:3px 0}details.gat{border-top:1px solid var(--line);padding:8px 0}details.gat summary{cursor:pointer;font-size:13px}.gr-grid>*{min-width:0}.kpi div{overflow-wrap:anywhere}@media(max-width:700px){.gr-grid{grid-template-columns:minmax(0,1fr)!important}.mk-rij{grid-template-columns:90px 1fr 56px}table.lijst.klein th,table.lijst.klein td{padding:6px 4px}table.lijst.klein tr>:nth-child(5),table.lijst.klein tr>:nth-child(6){display:none}.card:has(table.lijst.klein){overflow-x:auto}}table.lijst.klein{font-size:12.5px}table.lijst.klein td:first-child{min-width:0}table.lijst.klein th,table.lijst.klein td{white-space:nowrap;padding:8px 6px}table.lijst.klein td:first-child,table.lijst.klein th:first-child{white-space:normal}.num{white-space:nowrap}.card:has(>table.lijst.klein){overflow-x:auto}table.lijst.klein th{font-size:10.5px}.hm th{min-width:96px;text-transform:none;font-size:11.5px;letter-spacing:0}
.hm-wrap{overflow-x:auto}.lad td{min-width:92px;line-height:1.25}.lad td b{display:block;font-weight:600;font-size:12.5px}.lad-n{display:block;font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px;margin:0 auto;opacity:.85}td.lad-wij{background:#1f4fd1;color:#fff;font-weight:700;font-size:11px;box-shadow:0 0 0 2px #1f4fd1}td.lad-wij b{font-size:12.5px}.lad-leeg{background:none}.hm-wissel{display:inline-flex;border:1px solid var(--line);border-radius:99px;overflow:hidden}.hm-wissel button{font:inherit;font-size:12.5px;padding:5px 12px;border:0;background:#fff;cursor:pointer;color:var(--ink)}.hm-wissel button.on{background:var(--ink);color:#fff}.hm{border-collapse:separate;border-spacing:2px;font-size:12px;width:auto;table-layout:auto}.hm th{font-size:11px;font-weight:600;color:var(--muted);padding:4px 6px;text-align:center;max-width:90px;white-space:normal;vertical-align:bottom}.hm td{padding:5px 6px;text-align:center;border-radius:4px;min-width:58px;font-variant-numeric:tabular-nums;white-space:nowrap}.hm td.hm-v,.hm th.hm-v{text-align:left;min-width:60px}.hm .num{text-align:right}.hm-leeg{background:#f7f6f1}.hm-p td{text-align:left;font-weight:600;font-size:12.5px;padding:10px 4px 2px;color:var(--ink);background:none}
@media(max-width:900px){.mk{columns:1}}
/* ---------- mobiel (v18): elke tabel met kolomkoppen wordt een kaart per regel, brede grafieken schuiven, niets valt weg ---------- */
.svg-hint{display:none}
@media(max-width:700px){
nav{flex-wrap:wrap;overflow:visible;white-space:normal;gap:0 18px}nav a{padding:9px 0}
.btn,button{white-space:normal;max-width:100%}form{max-width:100%}.minmarge{flex-wrap:wrap}.minmarge input{width:80px}
.dk-rij form,.card form{flex-wrap:wrap}
table.kt,table.kt>tbody,table.kt>thead,table.kt tr,table.kt td{display:block;width:auto!important}
table.kt tr.kt-kop{display:none}
table.kt{border-top:0}
table.kt tr{border-top:1px solid var(--line);padding:10px 16px}
table.kt td{display:flex!important;justify-content:space-between;align-items:baseline;gap:14px;padding:3px 0!important;border:0!important;white-space:normal!important;text-align:right!important;max-width:none!important;min-width:0!important;overflow:visible!important;text-overflow:clip!important}
table.kt td::before{content:attr(data-l);flex:none;max-width:48%;text-align:left;font-size:12px;color:var(--muted);font-weight:500;text-transform:none;letter-spacing:0}
table.kt td:first-child{display:block!important;text-align:left!important;font-weight:600;font-size:14px;padding:0 0 4px!important;white-space:normal!important}
table.kt td:first-child::before{display:none}
table.kt td:empty{display:none!important}
table.kt td>*{max-width:100%}
table.kt .vnaam{white-space:normal!important;overflow:visible!important;text-overflow:clip!important}
table.lijst.klein.kt tr>:nth-child(5),table.lijst.klein.kt tr>:nth-child(6){display:flex!important}
.vtab.kt td:first-child{padding-left:0!important}
.match-op{white-space:normal!important;overflow:visible!important;text-overflow:clip!important}
.card.flush:has(table.kt){overflow-x:visible}
.svg-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -4px;padding:0 4px}
.svg-scroll>svg{max-width:none}
.svg-hint,.hm-hint{display:block;font-size:12px;color:var(--muted);margin:6px 0 0}
}
</style></head><body<?= ($OPEN || $ORUN) ? ' class="bezig"' : '' ?>>
<div class="top"><div class="bar"><div class="brand"><b>Prijsradar</b><span class="site"><?= e(preg_replace('/^www\./', '', $CFG['site'])) ?></span><span class="muted" style="font-size:11px;margin-left:8px" title="Softwareversie: op elke site hetzelfde">v<?= PRIJSRADAR_VERSIE ?></span></div>
<form method="get" class="meta" style="display:flex;align-items:center;gap:14px"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php if ($OPEN): ?><span class="hdr-run"><?= count($OPEN) ?> aanbieder<?= count($OPEN) > 1 ? 's worden' : ' wordt' ?> bijgewerkt</span><?php endif; ?>
  <span>Laatste inventaris: <?= e($laatst ?: 'nog niet') ?></span>
  <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--ink)">Markt
  <?php if (count($LANDEN) > 1): ?><select name="land" onchange="this.form.submit()" style="font-size:13px;padding:6px 10px"><?php foreach ($LANDEN as $l): ?><option <?= $l === $LAND ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php else: ?><span title="Volgt uit de extensie van <?= wn() ?>; een aanbieder uit een ander land voegt een markt toe"><?= e($LAND) ?></span><?php endif; ?></label>
  <noscript><button class="btn">Kies</button></noscript></form></div>
<nav><?php foreach ($tabs as $k => $t): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $t ?></a><?php endforeach; ?></nav></div>
<main>
<?php if ($OPEN || $ORUN): ?><div class="wacht" id="wacht"><i></i><span><b>Even wachten, de radar is aan het bijwerken.</b> <?= $OPEN ? count($OPEN) . ' aanbieder' . (count($OPEN) > 1 ? 's' : '') : '' ?><?= $OPEN && $ORUN ? ' en ' : '' ?><?= $ORUN ? 'best verkochte producten uit Lightspeed' : '' ?>. Laat dit tabblad open en klik nergens op tot dit klaar is, de pagina ververst vanzelf.</span></div><?php endif; ?>
<?php if ($melding): ?><div class="melding <?= $melding['ok'] ? '' : 'fout' ?>"><?= e($melding['tekst']) ?></div><?php endif; ?>

<?php /* ======================= OVERZICHT: per product de markt, varianten als regels ======================= */ if ($tab === 'overzicht'):
  $concRows = array_filter(alle_rijen(), function ($r) use ($LAND) { return !$r['is_eigen'] && $r['land'] === $LAND; });
  $MA = $eigen ? markt_alles(aanbod((int)$eigen['id']), $concRows, $conc) : [];
  $plat = []; foreach ($MA as $ms) foreach ($ms as $m) $plat[] = $m;
  $met = array_filter($plat, function ($m) { return $m['n'] > 0; });
  $goedkoopst = count(array_filter($met, function ($m) { return $m['pos'] === 1; }));
  $boven = count(array_filter($met, function ($m) { return $m['vsmed'] > 0.05; }));
  $dun = count(array_filter($met, function ($m) { return $m['n'] < drempel('min_aanbieders'); })); ?>
  <h1>Markt <?= e($LAND) ?></h1><p class="lead">Alle prijzen incl. btw</p>
  <?= startstappen($eigen, $conc) ?><?php if (!$eigen): ?><?php else: ?>
  <div class="kpis">
    <div class="kpi"><small>Vergeleken</small><div><?= count($met) ?></div><p>van <?= count($plat) ?> varianten in de radar</p></div>
    <div class="kpi"><small><?= wn() ?> goedkoopst</small><div style="color:var(--ok)"><?= $goedkoopst ?></div><p>varianten op plek 1, ook gedeeld</p></div>
    <div class="kpi"><small>Boven de mediaan</small><div style="color:<?= $boven ? '#b42318' : 'var(--ink)' ?>"><?= $boven ?></div><p>varianten waar <?= wn() ?> duurder is</p></div>
    <div class="kpi"><small>Dunne markt</small><div style="color:<?= $dun ? '#8a4b00' : 'var(--ink)' ?>"><?= $dun ?></div><p>minder dan <?= drempel('min_aanbieders') ?> aanbieders</p></div>
  </div>
  <?= g_marktbeeld($MA, marge_per_variant(), $conc) ?>
  <div class="sv-legenda" style="margin:0 0 12px"><b style="color:var(--ink);font-size:12.5px">Spreiding</b><span><i class="lg-wij"></i><?= wn() ?></span><span><i class="lg-stip"></i>concurrent</span><span><i class="lg-med"></i>mediaan</span><?= isset($bodem, $lo) && $bodem !== null && $bodem >= $lo ? '<span><i class="lg-bodem"></i>inkoop ' . wn() . ' (incl. btw)</span>' : '' ?><span>links goedkoopst, rechts duurst</span></div>
  <?php $leeg = array_filter($MA, function ($ms) { foreach ($ms as $m) if ($m['n']) return false; return true; });
  $volg = array_diff_key($MA, $leeg) + $leeg; $eerste = true;
  foreach ($volg as $pid => $ms): if (isset($leeg[$pid]) && $eerste): $eerste = false; ?>
  <details class="ov-leeg"><summary class="btn" style="list-style:none;margin:10px 0 16px">Nog geen concurrent gevonden · <?= count($leeg) ?> producten</summary>
  <?php endif; ?>
  <div class="card flush">
    <div class="samenvatting" style="padding:16px 20px;display:flex;justify-content:space-between;align-items:baseline;gap:12px">
      <h2 style="font-size:19px"><?= e(korte_titel($ms[0]['ons']['ptitel'])) ?></h2><a href="?tab=producten&p=<?= (int)$pid ?>" style="font-size:13px;font-weight:600">Details ›</a></div>
    <div class="ov-kop"><span>Variant</span><span>Prijs <?= wn() ?></span><span>Laagste prijs</span><span>Mediaan prijs</span><span>% vs mediaan</span><span>Positie</span><span>Spreiding</span></div>
    <?php foreach ($ms as $m): ?>
    <a class="ov-rij" href="?tab=producten&p=<?= (int)$pid ?>#v<?= (int)$m['ons']['vid'] ?>">
      <span class="vnaam" title="<?= e($m['naam']) ?>"><?= e($m['naam']) ?></span>
      <span class="num ours"><?= eur($m['ons']['prijs']) ?></span>
      <span><?php if ($m['laagste']): ?><span class="num"><?= eur($m['laagste']['prijs']) ?></span> <span class="muted" style="font-size:12px"><?= e($m['laagste']['bnaam']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></span>
      <span class="num"><?= $m['n'] ? eur($m['med']) : '<span class="muted">—</span>' ?></span>
      <span><?= $m['n'] ? pct($m['vsmed'], 'wij') : '<span class="muted">—</span>' ?></span>
      <span style="font-size:13px"><?= e(positie_tekst($m)) ?><?= $m['n'] && $m['n'] < drempel('min_aanbieders') ? ' <span title="Dunne markt: minder dan ' . drempel('min_aanbieders') . ' aanbieders" style="color:#8a4b00">⚠</span>' : '' ?></span>
      <span><?= mini_band($m) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; if (!$eerste): ?></details><?php endif; if (!$MA): ?><div class="card muted">Nog geen producten in de radar.</div><?php endif; endif; ?>

<?php /* ======================= GRAFIEKEN: de markt in één oogopslag ======================= */ elseif ($tab === 'grafieken'):
  $concRows = array_filter(alle_rijen(), function ($r) use ($LAND) { return !$r['is_eigen'] && $r['land'] === $LAND; });
  $MA = $eigen ? markt_alles(aanbod((int)$eigen['id']), $concRows, $conc) : [];
  $met = []; foreach ($MA as $ms) foreach ($ms as $m) if ($m['n']) $met[] = $m;
  // per concurrent: bij hoeveel varianten zijn zij goedkoper, gelijk of duurder dan wij
  $perConc = []; foreach ($conc as $cb) $perConc[(int)$cb['id']] = ['naam' => $cb['naam'], 'goedkoper' => 0, 'gelijk' => 0, 'duurder' => 0];
  foreach ($met as $m) foreach ($m['perBron'] as $bid => $c) { $d = $c['prijs'] - $m['ons']['prijs']; $perConc[$bid][$d < -0.005 ? 'goedkoper' : ($d > 0.005 ? 'duurder' : 'gelijk')]++; }
  $perConc = array_filter($perConc, function ($x) { return $x['goedkoper'] + $x['gelijk'] + $x['duurder'] > 0; });
  // horizontale staaf rond een nullijn
  $staven = function (array $items, callable $fmt) {
      if (!$items) return '<p class="muted" style="margin:0">Nog geen vergelijkbare varianten.</p>';
      $nul = count(array_filter($items, function ($i) { return abs($i['v']) < 0.005; })); $items = array_values(array_filter($items, function ($i) { return abs($i['v']) >= 0.005; }));
      $staart = $nul ? '<p class="muted" style="margin:10px 0 0;font-size:13px;padding-top:10px;border-top:1px solid var(--line)">' . ($items ? '+ ' : '') . $nul . ' varianten gelijk aan de markt</p>' : '';
      if (!$items) return $staart;
      $max = max(array_map(function ($i) { return abs($i['v']); }, $items)) ?: 1; $h = '';
      foreach ($items as $i) { $w = round(abs($i['v']) / $max * 50, 1); $kl = abs($i['v']) < 0.005 ? '#b9bcc2' : ($i['v'] > 0 ? '#d64545' : '#2e9e4f');
          $h .= '<a class="gr-rij" href="' . $i['link'] . '"><span class="vnaam" title="' . e($i['naam']) . '">' . e($i['naam']) . '</span><span class="gr-bak"><span class="gr-nul"></span><span class="gr-staaf" style="background:' . $kl . ';width:' . max($w, 0.6) . '%;' . ($i['v'] >= 0 ? 'left:50%' : 'right:50%') . '"></span></span><span class="num" style="text-align:right;color:' . ($kl === '#b9bcc2' ? 'var(--muted)' : $kl) . '">' . $fmt($i['v']) . '</span></a>'; }
      return $h . $staart;
  };
  $a = array_map(function ($m) { return ['naam' => $m['naam'], 'v' => round($m['vsmed'], 1), 'link' => '?tab=producten&p=' . $m['pid'] . '#v' . $m['ons']['vid']]; }, $met);
  usort($a, function ($x, $y) { return $y['v'] <=> $x['v']; });
  $b = array_map(function ($m) { return ['naam' => $m['naam'], 'v' => round($m['ons']['prijs'] - $m['laagste']['prijs'], 2), 'link' => '?tab=producten&p=' . $m['pid'] . '#v' . $m['ons']['vid']]; }, $met);
  usort($b, function ($x, $y) { return $y['v'] <=> $x['v']; });
  $eurFmt = function ($v) { return ($v > 0.005 ? '+' : ($v < -0.005 ? '−' : '')) . '€' . number_format(abs($v), 2, ',', '.'); };
  $pctFmt = function ($v) { return ($v > 0.05 ? '+' : '') . number_format($v, 1, ',', '') . '%'; };
  $weken = (int)db()->query("SELECT COUNT(DISTINCT strftime('%Y-%W', tijd)) FROM meting")->fetchColumn(); ?>
  <h1>Grafieken</h1><p class="lead">Markt <?= e($LAND) ?> · alle prijzen incl. btw</p>
  <?= startstappen($eigen, $conc) ?><?php if (!$eigen): ?><?php else: ?>
  <?php $kop = function ($titel, $id) { return '<div class="gr-kop"><h2>' . e($titel) . '</h2><button type="button" class="gr-open" onclick="document.getElementById(\'' . $id . '\').showModal()">Alle data ›</button></div>'; };
    // volledige tabel per variant, voor de pop-ups
    $allesRijen = $met; $OMd = omzet_per_product(); $S = markt_stats($met, $conc, $OMd['omzet'] ?? []); ?>
  <?= g_kpis($S, $OMd['bron'] ?? 'geen') ?>
  <?php $MGall = marge_per_variant(); $RM = g_met_marge($met, $MGall); ?>
  <h2 class="gr-sectie">Marge en markt</h2>
  <?php if ($RM): ?><?= g_marge_kpis($RM, $OMd['omzet'] ?? []) ?><?php endif; ?>
  <div class="card" style="margin-bottom:16px"><div class="gr-kop"><h2>Marge × marktpositie</h2></div><?= g_marge_matrix($RM) ?></div>
  <div class="card" style="margin-bottom:16px"><div class="gr-kop"><h2>Kan <?= wn() ?> de goedkoopste volgen?</h2></div><?= g_volgen($RM, $OMd['omzet'] ?? []) ?></div>
  <h2 class="gr-sectie">Markt</h2>
  <div class="gr-grid" style="margin-bottom:16px">
    <div class="card"><div class="gr-kop"><h2><?= wn() ?> vs elke aanbieder</h2></div><?= g_index_aanbieders($S) ?></div>
    <div class="card"><div class="gr-kop"><h2>Profiel per aanbieder</h2></div><?= g_profiel($S) ?></div>
    <div class="card"><div class="gr-kop"><h2>Stunters</h2></div><?= g_stunters($S) ?></div>
    <div class="card"><div class="gr-kop"><h2>Best verkocht: positie <?= wn() ?></h2></div><?= g_bestverkocht($met, $OMd['omzet'] ?? [], $MGall) ?></div>
  </div>
  <div class="card" style="margin-bottom:16px"><div class="gr-kop"><h2>Prijsladder: waar staat de variant van <?= wn() ?></h2><span class="hm-wissel"><button type="button" class="on" onclick="hmWissel(this,'lad')">Op prijs</button><button type="button" onclick="hmWissel(this,'hm')">Per aanbieder</button></span></div>
    <div id="v-lad"><p class="gr-uitleg">Per variant alle aanbieders van goedkoopst (links) naar duurst (rechts). <b style="color:#1f4fd1"><?= wn() ?></b> = de variant van <?= wn() ?> op zijn plek · <span style="color:#d64545">■</span> goedkoper dan <?= wn() ?> · <span style="color:#2e9e4f">■</span> duurder dan <?= wn() ?> · bij gelijke prijs staat <?= wn() ?> vooraan</p><?= g_ladder($met, $S) ?></div>
    <div id="v-hm" style="display:none"><?= g_heatmap($met, $S) ?></div></div>
  <script>function hmWissel(b,w){b.parentNode.querySelectorAll('button').forEach(function(x){x.classList.toggle('on',x===b);});document.getElementById('v-lad').style.display=w==='lad'?'':'none';document.getElementById('v-hm').style.display=w==='hm'?'':'none';}</script>
  <h2 class="gr-sectie">Kansen en risico's</h2>
  <div class="card" style="margin-bottom:16px"><div class="gr-kop"><h2>Prijsadvies: waar bijsturen</h2></div><?= g_advies($met, $OMd['omzet'] ?? [], marge_per_variant()) ?></div>
  <?php $plat = []; foreach ($MA as $ms) foreach ($ms as $m) $plat[] = $m; ?>
  <div class="gr-grid" style="margin-bottom:16px">
    <div class="card"><div class="gr-kop"><h2>Hoe druk is de markt</h2></div><?= g_drukte($plat) ?></div>
    <div class="card"><div class="gr-kop"><h2>Prijsspreiding</h2></div><?= g_spreiding($met) ?></div>
  </div>
  <h2 class="gr-sectie">Gedrag van aanbieders</h2>
  <div class="gr-grid" style="margin-bottom:16px">
    <div class="card"><div class="gr-kop"><h2>Prijswijzigingen</h2></div><?= g_wijzigingen($conc) ?></div>
    <div class="card"><div class="gr-kop"><h2>Assortimentsgat</h2></div><?= g_gat($conc, $concRows, aanbod((int)$eigen['id'], true)) ?></div>
  </div>
  <div class="card" style="margin-bottom:16px"><div class="gr-kop"><h2>Afwijkers: controleer de koppeling</h2></div><?= g_afwijkers($met) ?></div>
  <h2 class="gr-sectie">Detail</h2>
  <div class="gr-grid">
    <div class="card"><?= $kop('Prijs ' . wn() . ' vs mediaan', 'pop-med') ?><p class="gr-uitleg"><span style="color:#2e9e4f">■</span> <?= wn() ?> goedkoper &nbsp; <span style="color:#d64545">■</span> <?= wn() ?> duurder</p><?= $staven($a, $pctFmt) ?></div>
    <div class="card"><?= $kop('Verschil met de goedkoopste', 'pop-laag') ?><p class="gr-uitleg">Prijs <?= wn() ?> min de laagste concurrent, per variant</p><?= $staven($b, $eurFmt) ?></div>
    <div class="card"><?= $kop('Per concurrent', 'pop-conc') ?><p class="gr-uitleg"><span style="color:#d64545">■</span> goedkoper dan <?= wn() ?> &nbsp; <span style="color:#b9bcc2">■</span> gelijk &nbsp; <span style="color:#2e9e4f">■</span> duurder dan <?= wn() ?></p>
      <?php if (!$perConc): ?><p class="muted" style="margin:0">Nog geen concurrenten vergeleken.</p><?php endif;
      foreach ($perConc as $bid => $x): $t = $x['goedkoper'] + $x['gelijk'] + $x['duurder']; ?>
      <a class="gr-rij" href="?tab=concurrenten&bron=<?= (int)$bid ?>" style="grid-template-columns:150px 1fr 70px"><span class="vnaam"><b><?= e($x['naam']) ?></b></span>
        <span class="gr-stapel"><?php foreach (['goedkoper' => '#d64545', 'gelijk' => '#b9bcc2', 'duurder' => '#2e9e4f'] as $s => $kl) if ($x[$s]): ?><span style="flex:<?= $x[$s] ?>;background:<?= $kl ?>" title="<?= $x[$s] ?> <?= $s ?>"><?= $x[$s] ?></span><?php endif; ?></span>
        <span class="num muted" style="text-align:right"><?= $t ?> var.</span></a>
      <?php endforeach; ?></div>
    <div class="card"><h2>Prijsverloop</h2><p class="gr-uitleg">Prijs <?= wn() ?>, de mediaan en elke concurrent per week</p>
      <?php $TR = trend($eigen, $conc, $LAND); $namen = []; foreach ($conc as $cb) $namen[(int)$cb['id']] = $cb['naam'];
      if ($TR): echo trend_svg($TR, $namen); ?><p class="gr-uitleg" style="margin:8px 0 0"><b style="color:var(--ink)">━</b> <?= wn() ?> vs mediaan (gemiddeld) &nbsp; <b style="color:#b9bcc2">━</b> concurrent vs <?= wn() ?> (gemiddeld) · boven 0 = duurder<?= count($TR) < 2 ? ' · de lijn ontstaat vanaf de tweede week' : '' ?></p>
      <?php else: ?><div class="gr-leeg"><b>Nog geen metingen</b><span>De lijnen verschijnen na de eerste wekelijkse update.</span></div><?php endif; ?></div>
  </div>
  <?php foreach (['pop-med' => ['Prijs ' . wn() . ' vs mediaan', 'vsmed'], 'pop-laag' => ['Verschil met de goedkoopste', 'laag']] as $pid => [$titel, $sort]):
    $lijst = $allesRijen; usort($lijst, function ($x, $y) use ($sort) { $f = function ($m) use ($sort) { return $sort === 'vsmed' ? $m['vsmed'] : $m['ons']['prijs'] - $m['laagste']['prijs']; }; return $f($y) <=> $f($x); }); ?>
  <dialog id="<?= $pid ?>" class="pop"><div class="pop-kop"><h2><?= e($titel) ?> <span class="muted" style="font-size:13px;font-weight:500">incl. btw · <?= count($lijst) ?> varianten</span></h2><button type="button" class="btn" onclick="this.closest('dialog').close()">Sluiten</button></div>
    <div class="pop-body"><table class="lijst"><tr><th>Variant</th><th style="text-align:right">Prijs <?= wn() ?></th><th style="text-align:right">Laagste prijs</th><th>Door</th><th style="text-align:right">Mediaan prijs</th><th style="text-align:right">% vs mediaan</th><th style="text-align:right">€ vs laagste</th><th style="text-align:right">Aanbieders</th></tr>
    <?php foreach ($lijst as $m): $dl = $m['ons']['prijs'] - $m['laagste']['prijs']; ?>
      <tr onclick="location.href='?tab=producten&p=<?= (int)$m['pid'] ?>#v<?= (int)$m['ons']['vid'] ?>'" style="cursor:pointer"><td style="white-space:normal"><?= e($m['naam']) ?></td>
        <td class="num" style="text-align:right"><?= eur($m['ons']['prijs']) ?></td><td class="num" style="text-align:right"><?= eur($m['laagste']['prijs']) ?></td><td class="muted" style="font-size:13px"><?= e($m['laagste']['bnaam']) ?></td>
        <td class="num" style="text-align:right"><?= eur($m['med']) ?></td><td style="text-align:right"><?= pct($m['vsmed'], 'wij') ?></td>
        <td class="num <?= $dl > 0.005 ? 'rood' : ($dl < -0.005 ? 'groen' : '') ?>" style="text-align:right"><?= $eurFmt(round($dl, 2)) ?></td>
        <td class="num" style="text-align:right"><?= $m['n'] ?><?= $m['n'] < drempel('min_aanbieders') ? ' <span title="Dunne markt" style="color:#8a4b00">⚠</span>' : '' ?></td></tr>
    <?php endforeach; ?></table></div></dialog>
  <?php endforeach; ?>
  <dialog id="pop-conc" class="pop"><div class="pop-kop"><h2>Per concurrent <span class="muted" style="font-size:13px;font-weight:500">incl. btw</span></h2><button type="button" class="btn" onclick="this.closest('dialog').close()">Sluiten</button></div>
    <div class="pop-body"><?php foreach ($perConc as $bid => $x): ?>
      <h3 style="margin:18px 0 8px"><?= e($x['naam']) ?> <span class="muted" style="font-size:13px;font-weight:500"><?= $x['goedkoper'] ?> goedkoper · <?= $x['gelijk'] ?> gelijk · <?= $x['duurder'] ?> duurder dan <?= wn() ?></span></h3>
      <table class="lijst"><tr><th>Variant <?= wn() ?></th><th>Hun variant</th><th style="text-align:right">Prijs <?= wn() ?></th><th style="text-align:right">Hun prijs</th><th style="text-align:right">% vs <?= wn() ?></th></tr>
      <?php $rij = []; foreach ($met as $m) if (isset($m['perBron'][$bid])) $rij[] = $m;
        usort($rij, function ($p, $q) use ($bid) { return ($p['perBron'][$bid]['prijs'] - $p['ons']['prijs']) <=> ($q['perBron'][$bid]['prijs'] - $q['ons']['prijs']); });
        foreach ($rij as $m): $c = $m['perBron'][$bid]; ?>
        <tr><td style="white-space:normal"><?= e($m['naam']) ?></td><td class="muted" style="white-space:normal;font-size:13px"><?= e(volle_naam($c['ptitel'], $c['vtitel'])) ?></td>
          <td class="num" style="text-align:right"><?= eur($m['ons']['prijs']) ?></td><td class="num" style="text-align:right"><?= eur($c['prijs']) ?></td>
          <td style="text-align:right"><?= pct(($c['prijs'] - $m['ons']['prijs']) / $m['ons']['prijs'] * 100, 'hen') ?></td></tr>
      <?php endforeach; ?></table>
    <?php endforeach; ?></div></dialog>
  <?php endif; ?>

<?php /* ======================= ONZE SHOP (de bron waartegen alles wordt vergeleken) ======================= */ elseif ($tab === 'shop'):
  $br = $eigen ? laatste_run((int)$eigen['id']) : null; $rows = $eigen ? aanbod((int)$eigen['id']) : []; $prods = []; foreach ($rows as $x) $prods[$x['pid']][] = $x; ?>
  <div class="card" style="border:2px solid var(--ours)"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px">
    <div><div style="display:flex;align-items:center;gap:12px"><h1 style="margin:0"><?= e($eigen['naam'] ?? $CFG['site']) ?></h1><?= chip('referentie', 'acc') ?></div>
      <p style="margin:8px 0 0"><?= api_ingesteld() ? chip('via API', 'ok') : chip('API niet ingesteld', 'warn') ?>
        <?php if ($eigen): ?><span class="muted" style="margin-left:8px"><?php
          if ($br && !$br['fouten']) echo (int)$br['producten'] . ' producten · ' . (int)$br['varianten'] . ' varianten gevonden';
          elseif ($br && $br['fouten']) echo 'Laatste bijwerking mislukt: ' . e($br['log']);
          elseif (api_ingesteld()) echo 'Nog niet bijgewerkt';
          else echo $eigen['listing_url'] !== '' ? e(preg_replace('#^https?://#', '', $eigen['listing_url'])) : e($eigen['domein']); ?></span><?php endif; ?></p></div>
    <div style="white-space:nowrap;display:flex;gap:8px"><?php if (!$eigen): ?><form class="inline" method="post" onsubmit="this.querySelector('button').textContent='Bezig…'"><input type="hidden" name="actie" value="basis"><input type="hidden" name="tab" value="shop"><button class="btn dark" style="background:var(--ours);border-color:var(--ours)"><?= wn() ?> ophalen</button></form>
      <?php else: ?><form class="inline" method="post" onsubmit="this.querySelector('button').textContent='Bezig…'"><input type="hidden" name="actie" value="inventaris"><input type="hidden" name="id" value="<?= $eigen['id'] ?>"><input type="hidden" name="tab" value="shop"><button class="btn dark" style="background:var(--ours);border-color:var(--ours)">Bijwerken</button></form>
      <?php if (!api_ingesteld()): ?><form class="inline" method="post" onsubmit="this.querySelector('button').textContent='Bezig…'"><input type="hidden" name="actie" value="basis"><input type="hidden" name="tab" value="shop"><button class="btn">Opnieuw zoeken</button></form><?php endif; endif; ?></div></div>
    <?php if ($eigen && isset($OPEN[(int)$eigen['id']])): ?><div style="margin-top:14px"><?= balk($OPEN[(int)$eigen['id']]) ?></div><?php endif; ?>
    <?php if ($eigen): $ean = count(array_filter($rows, function ($x) { return $x['ean'] !== ''; })); ?>
    <div class="stats"><div><small>Platform</small><b><?= e($eigen['platform']) ?></b></div><div><small>In de radar</small><b><?= count($prods) ?> producten · <?= count($rows) ?> var.</b></div>
      <div><small>Met EAN in de radar</small><b><?= count($rows) ? round($ean / count($rows) * 100) : 0 ?>%</b></div><div><small>Land</small><b><?= e($eigen['land']) ?></b></div><div><small>Bijgewerkt</small><b><?= e($br['eind'] ?? 'nog niet') ?></b></div></div>
    <?php endif; ?></div>
  <?php $OMk = omzet_per_product(); $orunK = db()->query('SELECT * FROM omzet_run WHERE eind IS NULL ORDER BY id DESC LIMIT 1')->fetch(); $MGk = marge_per_variant(); $mgMetK = count(array_filter($MGk, function ($x) { return $x['inkoop'] !== null; })); $mgTk = $MGk ? max(array_column($MGk, 'gemeten')) : null; ?>
<?php ob_start(); ?><?php $omLaatst = db()->query('SELECT MAX(eind) FROM omzet_run')->fetchColumn(); $omProd = count(array_filter($OMk['omzet'] ?? [], function ($o) { return $o > 0; })); ?>
    <div class="dk-rij"><span><?= $OMk['bron'] === 'geen' ? 'nog niet opgehaald' : ($omLaatst ? 'bijgewerkt ' . e(substr($omLaatst, 0, 16)) . ' · ' : '') . ($OMk['bron'] === '12 maanden' ? number_format($OMk['orders'], 0, ',', '.') . ' orders (' . omzet_periode() . ') gelezen · ' . $omProd . ' producten met omzet' : 'indicatie op basis van totaal ooit verkocht (' . omzet_periode() . ' nog niet compleet)') ?> <span class="muted" style="font-size:12.5px">· omzet incl. btw per product uit de orderregels in Lightspeed, geannuleerde orders en retouren niet meegeteld</span></span>
    <?php if ($orunK): $ov = json_decode((string)$orunK['voortgang'], true) ?: ['pct' => 0, 'tekst' => 'Starten…']; ?>
    <div class="runbalk" id="omzetbalk" data-orun="<?= (int)$orunK['id'] ?>" style="max-width:520px;margin:8px 0 0"><div class="rb-tekst"><span><?= e($ov['tekst']) ?></span><b><?= (int)$ov['pct'] ?>%</b></div><div class="rb-bak"><div class="rb-vul" style="width:<?= (int)$ov['pct'] ?>%"></div></div></div>
    <script>(function(){var b=document.getElementById('omzetbalk'),id=b.dataset.orun;function s(){var f=new FormData();f.append('actie','omzetstap');f.append('run',id);fetch(location.pathname,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){if(j.klaar)j.pct=100;b.querySelector('.rb-tekst span').textContent=j.tekst;b.querySelector('.rb-tekst b').textContent=(j.pct||0)+'%';b.querySelector('.rb-vul').style.width=(j.pct||0)+'%';if(j.klaar)setTimeout(function(){location.reload();},600);else setTimeout(s,300);}).catch(function(){setTimeout(s,3000);});}s();})();</script>
    <?php elseif (api_ingesteld()): ?><form method="post"><input type="hidden" name="actie" value="omzet"><button class="btn" style="padding:6px 12px;white-space:nowrap">Best verkochte producten bijwerken uit Lightspeed</button></form><?php endif; ?></div>
  <?php $celO = ob_get_clean(); ob_start(); ?><div class="dk-rij"><span><?= $MGk ? 'bijgewerkt ' . e(substr($mgTk, 0, 16)) . ' · ' . $mgMetK . ' van ' . count($MGk) . ' varianten hebben een inkoopprijs (' . round($mgMetK / max(1, count($MGk)) * 100) . '%)' : 'nog niet berekend' ?> <span class="muted" style="font-size:12.5px">· verkoopprijs excl. btw min inkoopprijs uit Lightspeed · <?= e($GLOBALS['CFG']['marge_voetnoot'] ?? 'marge vóór verzend- en betaalkosten') ?></span></span>
    <form method="post" class="minmarge" title="Onder deze marge kleurt de radar rood en valt een variant in 'probleem'"><input type="hidden" name="actie" value="minmarge"><label>Minimale marge <input name="min" inputmode="decimal" value="<?= g_min() === null ? '' : e(rtrim(rtrim(number_format(g_min(), 1, ',', ''), '0'), ',')) ?>" placeholder="bv. 30" style="width:56px;padding:5px 8px;font-size:13px">%</label><button class="btn" style="padding:6px 10px">Opslaan</button></form>
    <?php if (api_ingesteld()): ?><form method="post" onsubmit="this.querySelector('button').textContent='Bezig, even geduld…'"><input type="hidden" name="actie" value="marge"><button class="btn" style="padding:6px 12px;white-space:nowrap">Bereken marge per product</button></form><?php endif; ?></div>
  <?php $celM = ob_get_clean(); ?>
  <?php $dbAlle = db_overzicht(); $dbKwets = strpos(realpath(db_pad()) ?: db_pad(), realpath(__DIR__) . '/') === 0; // database binnen de code-map = kwetsbaar bij een update
    $dbTxt = e(db_pad()) . ($dbKwets ? ' <span class="chip warn">binnen de map prijsradar</span> <span class="muted" style="font-size:12.5px">vervang bij een update nooit de hele map, anders is je data weg; vraag de hoster om schrijfrecht op ' . e(db_plekken()[0] ?? '') . '</span>' : '');
    foreach ($dbAlle as $x) if (!$x['actief']) $dbTxt .= '<br><span class="muted" style="font-size:12.5px">ook gevonden: ' . e($x['pad']) . ' · site ' . e($x['site']) . ' · ' . ($x['aanbieders'] ?? '?') . ' aanbieders · ' . $x['kb'] . ' kB</span>'; ?>
  <?= dekking_kaart($br, [['Database', $dbTxt, $dbKwets ? '<span class="chip amb">let op</span>' : '<span class="chip ok">✓</span>'], ['Best verkocht', $celO, $OMk['bron'] === '12 maanden' ? '<span class="chip ok">✓</span>' : ''], ['Marge', $celM, $MGk ? '<span class="chip ok">✓</span>' : '']]) ?>
  <?php $allesR = $eigen ? aanbod((int)$eigen['id'], true) : []; $pp = []; foreach ($allesR as $x) $pp[$x['pid']][] = $x;
    $in = array_filter($pp, function ($vs) { return (int)$vs[0]['in_radar'] === 1; }); $uit = array_filter($pp, function ($vs) { return (int)$vs[0]['in_radar'] !== 1; });
    $online = function ($vs) { return !isset($vs[0]['zichtbaar']) || (int)$vs[0]['zichtbaar'] === 1; };
    // niet-online altijd onderaan, binnen elke groep (stabiel: volgorde blijft verder gelijk)
    $sorteer = function ($lijst) use ($online) { $a = array_filter($lijst, $online); $b = array_filter($lijst, function ($vs) use ($online) { return !$online($vs); }); return $a + $b; };
    $in = $sorteer($in);
    $uitOnline = array_filter($uit, $online); $uitOffline = array_filter($uit, function ($vs) use ($online) { return !$online($vs); });
    $GLOBALS['OMZET'] = omzet_per_product(); $GLOBALS['MARGE'] = marge_per_variant();
    $rij = function ($vs, $sleep) { $p = $vs[0]; $off = isset($p['zichtbaar']) && !$p['zichtbaar']; ob_start(); ?>
    <details class="pitem" <?= (int)$p['in_radar'] === 1 ? 'open' : '' ?> draggable="<?= $sleep ? 'true' : 'false' ?>" data-id="<?= $p['pid'] ?>">
      <summary class="prow">
        <?php if ($sleep): ?><span class="handle" title="Sleep om te ordenen">⋮⋮</span><?php else: ?><span class="handle" style="visibility:hidden">⋮⋮</span><?php endif; ?>
        <form method="post" class="inline" onclick="event.stopPropagation()"><input type="hidden" name="actie" value="meenemen"><input type="hidden" name="pid" value="<?= $p['pid'] ?>">
          <label class="switch"><input type="checkbox" name="aan" value="1" <?= (int)$p['in_radar'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()"><span></span></label></form>
        <div style="flex:1;min-width:0"><b style="color:<?= $off ? '#a8adb3' : ($sleep ? 'var(--ink)' : '#3f4650') ?>"><?= e(korte_titel($p['ptitel'])) ?></b> <?= $off ? chip('niet online', 'warn') : '' ?>
          <span class="muted" style="font-size:13px;margin-left:8px;<?= $off ? 'color:#b9bdc3' : '' ?>"><?= count($vs) ?> variant<?= count($vs) === 1 ? '' : 'en' ?></span></div>
        <?php $mp = array_values(array_filter(array_map(function ($v) { return $GLOBALS['MARGE'][(string)$v['vext']]['pct'] ?? null; }, $vs), function ($x) { return $x !== null; }));
          if ($mp): $lo = min($mp); $hi = max($mp); ?><span class="num pmarge" title="Marge op verkoopprijs excl. btw, uit Lightspeed" style="color:<?= $lo < g_minv() ? '#b42318' : 'var(--muted)' ?>"><?= round($lo) === round($hi) ? round($lo) . '%' : round($lo) . '–' . round($hi) . '%' ?> marge</span><?php elseif ($GLOBALS['MARGE']): ?><span class="muted pmarge" style="font-size:12px">inkoop ontbreekt</span><?php endif; ?>
        <?php $o = $GLOBALS['OMZET']['omzet'][$p['ext_id']] ?? null; if ($o !== null): ?><span class="num muted pomzet" title="Omzet incl. btw<?= $GLOBALS['OMZET']['bron'] === 'indicatie' ? ' (indicatie)' : ', ' . omzet_periode() ?>"><?= eur($o) ?></span><?php endif; ?>
        <span class="pijl">›</span>
      </summary>
      <table class="vtab"><tr><th>Variant</th><th>Inhoud</th><th>Prijs incl. btw</th><th>Inkoop excl. btw</th><th>Marge € · %</th><th>EAN</th><th>Levering</th></tr>
      <?php foreach ($vs as $v): ?><tr><td class="vnaam" title="<?= e(volle_naam($v['ptitel'], $v['vtitel'])) ?>"><?= e(volle_naam($v['ptitel'], $v['vtitel'])) ?></td><td class="num"><?= maat($v) ?></td><td class="num ours"><?= eur($v['prijs']) ?></td><?php $mg = $GLOBALS['MARGE'][(string)$v['vext']] ?? null; ?><td class="num"><?= $mg && $mg['inkoop'] !== null ? eur($mg['inkoop']) : '<span class="muted">—</span>' ?></td><td class="num"<?= $mg && $mg['pct'] !== null && $mg['pct'] < g_minv() ? ' style="color:#b42318"' : '' ?> title="<?= $mg && $mg['inkoop'] !== null ? 'Verkoop excl. btw ' . eur($mg['excl']) . ' − inkoop ' . eur($mg['inkoop']) : '' ?>"><?= !$mg ? '<span class="muted">—</span>' : ($mg['pct'] !== null ? '€ ' . number_format($mg['eur'], 2, ',', '.') . ' · ' . round($mg['pct']) . '%' : '<span class="muted" style="font-family:inherit;font-size:13px">inkoop ontbreekt</span>') ?></td><td class="num muted"><?= e($v['ean']) ?></td><td><?= $v['op_voorraad'] ? chip('leverbaar', 'ok') : chip('niet leverbaar', 'warn') ?></td></tr><?php endforeach; ?><?php if ($GLOBALS['MARGE']): ?><tr><td colspan="7" class="muted" style="font-size:12px;border:0;padding-top:4px">Inkoop = inkoopprijs excl. btw uit Lightspeed · marge = verkoopprijs excl. btw min inkoopprijs · <?= e($GLOBALS['CFG']['marge_voetnoot'] ?? 'marge vóór verzend- en betaalkosten') ?></td></tr><?php endif; ?></table>
    </details>
  <?php return ob_get_clean(); }; ?>
  <?php if ($pp): ?>
  <style>
  .pitem{border-top:1px solid var(--line);background:var(--card)}.pitem:first-child{border-top:0}.pitem.drag{opacity:.4}
  .prow{display:flex;align-items:center;gap:14px;padding:12px 16px;cursor:pointer;list-style:none}.prow::-webkit-details-marker{display:none}
  .pijl{color:#9aa0a6;font-size:20px;transition:transform .15s}.pitem[open] .pijl{transform:rotate(90deg)}
  .vtab{margin:0 0 10px;border-top:1px solid var(--line)}.vtab th{white-space:nowrap}.vtab th:first-child,.vtab td:first-child{padding-left:86px}
  .handle{cursor:grab;color:#9aa0a6;font-size:18px;letter-spacing:-3px;user-select:none}
  .switch{position:relative;display:inline-block;width:40px;height:24px;cursor:pointer}.switch input{opacity:0;width:0;height:0}
  .switch span{position:absolute;inset:0;background:#d6d4cd;border-radius:99px;transition:.15s}.switch span:before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:99px;transition:.15s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
  .switch input:checked+span{background:#2e9e4f}.switch input:checked+span:before{transform:translateX(16px)}
  .scheiding{display:flex;align-items:center;gap:12px;margin:22px 0 10px;color:var(--muted);font-size:13px;font-weight:600}.scheiding:before,.scheiding:after{content:"";flex:1;height:1px;background:#cfccc3}
  </style>
  <?php $srt = instelling('sortering') ?: 'hand'; $OM = omzet_per_product(); $heeftOmzet = !empty($OM['omzet']); if ($srt === 'omzet' && !$heeftOmzet) $srt = 'hand'; $orun = db()->query('SELECT * FROM omzet_run WHERE eind IS NULL ORDER BY id DESC LIMIT 1')->fetch(); ?>
  <div class="sorteer-kop"><h2>In de radar · <?= count($in) ?></h2>
    <form method="post" class="sorteer"><input type="hidden" name="actie" value="sortering"><span class="muted">Volgorde</span>
      <?php foreach (['hand' => 'Eigen volgorde', 'omzet' => 'Meest verkocht', 'naam' => 'Naam'] as $k => $t): $uit = $k === 'omzet' && !$heeftOmzet; ?><button name="s" value="<?= $k ?>" class="<?= $srt === $k ? 'on' : '' ?>"<?= $uit ? ' disabled title="Kan pas als de best verkochte producten zijn opgehaald (Dekking, hierboven)" style="opacity:.4;cursor:not-allowed"' : '' ?>><?= $t ?></button><?php endforeach; ?></form></div>
  <?php if ($srt === 'hand'): ?><p class="muted omzetregel">Sleep om de volgorde te wijzigen.</p><?php endif; ?>
  <div class="card flush" id="inradar"><?php foreach ($in as $vs) echo $rij($vs, $srt === 'hand'); if (!$in): ?><div class="prow muted" style="cursor:default">Nog niets geselecteerd.</div><?php endif; ?></div>
  <div class="scheiding">Niet in de radar · online · <?= count($uitOnline) ?></div>
  <div class="card flush"><?php foreach ($uitOnline as $vs) echo $rij($vs, false); if (!$uitOnline): ?><div class="prow muted" style="cursor:default">Geen.</div><?php endif; ?></div>
  <div class="scheiding">Niet online · alleen in de backoffice · <?= count($uitOffline) ?></div>
  <div class="card flush" style="background:#faf9f6"><?php foreach ($uitOffline as $vs) echo $rij($vs, false); if (!$uitOffline): ?><div class="prow muted" style="cursor:default">Geen.</div><?php endif; ?></div>
  <script>
  (function(){var box=document.getElementById('inradar'),drag=null;
   box.addEventListener('dragstart',function(e){drag=e.target.closest('.pitem');drag.classList.add('drag');e.dataTransfer.effectAllowed='move';});
   box.addEventListener('dragover',function(e){e.preventDefault();var t=e.target.closest('.pitem');if(!t||t===drag)return;var r=t.getBoundingClientRect();box.insertBefore(drag,(e.clientY-r.top)>r.height/2?t.nextSibling:t);});
   box.addEventListener('dragend',function(){drag.classList.remove('drag');var f=new FormData();f.append('actie','volgorde');box.querySelectorAll('.pitem[data-id]').forEach(function(r){f.append('ids[]',r.dataset.id);});fetch(location.pathname,{method:'POST',body:f,credentials:'same-origin'});});
  })();
  </script>
  <?php endif; ?>

<?php /* ======================= PRODUCTEN: prijs per productvariant in de markt ======================= */ elseif ($tab === 'producten'):
  if (!$eigen): ?><?= startstappen($eigen, $conc) ?>
<?php else:
  $rows = aanbod((int)$eigen['id']); $prods = []; foreach ($rows as $r) if ($r['inhoud_l'] !== null) $prods[$r['pid']][] = $r;
  $sel = (int)($_GET['p'] ?? 0);
  if (!$sel || !isset($prods[$sel])) { $sel = 0; if (!$sel && $prods) { $k = array_keys($prods); $sel = $k[0]; } }
  $concRows = array_filter(alle_rijen(), function ($r) use ($LAND) { return !$r['is_eigen'] && $r['land'] === $LAND; }); ?>
  <div class="pchips"><?php foreach ($prods as $pid => $vs): ?><a href="?tab=producten&p=<?= $pid ?>" class="<?= $pid === $sel ? 'on' : '' ?>"><?= e(korte_titel($vs[0]['ptitel'])) ?></a><?php endforeach; ?></div>
  <?php if ($sel && isset($prods[$sel])): $vs = $prods[$sel]; ?>
  <h1><?= e(korte_titel($vs[0]['ptitel'])) ?></h1>
  <?php $perInhoud = per_inhoud($vs);
  foreach ($perInhoud as $inh => $groep): $KE = kies_eigen((int)$sel, (string)$inh, $groep, $rows); $ons = $KE['ons']; if ($KE['door'] === 'jij') $groep = [$ons];
    $perBron = []; $keuzes = [];
    foreach ($conc as $cb) { $k = kies_voor_bron($ons, $concRows, (int)$cb['id']); if (!$k['kand']) continue; $keuzes[(int)$cb['id']] = $k + ['naam' => $cb['naam']]; if ($k['gekozen']) $perBron[(int)$cb['id']] = $k['gekozen']; }
    $p = array_map(function ($c) { return (float)$c['prijs']; }, array_values($perBron)); sort($p);
    $n = count($p); $med = $n ? ($n % 2 ? $p[intdiv($n, 2)] : ($p[$n / 2 - 1] + $p[$n / 2]) / 2) : null;
    $pos = 1 + count(array_filter($p, function ($x) use ($ons) { return $x < (float)$ons['prijs'] - 0.005; })); $gedeeld = count(array_filter($p, function ($x) use ($ons) { return abs($x - (float)$ons['prijs']) <= 0.005; })) > 0; ?>
  <div class="card flush vblok" id="i<?= anker((string)$inh) ?>">
    <div class="samenvatting">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:14px">
        <?php $autoNaam = implode(' / ', array_unique(array_map(function ($g) { return volle_naam($g['ptitel'], $g['vtitel']); }, $groep))); $toonNaam = weergavenaam((int)$sel, (string)$inh, $autoNaam); ?>
        <div class="naamvak"><h2 style="font-size:22px"><?= e($toonNaam) ?> <span class="muted" style="font-size:13px;font-weight:500">incl. btw</span>
          <button type="button" class="edit" title="Naam wijzigen" onclick="var v=this.closest('.naamvak');v.classList.add('bewerk');var i=v.querySelector('.naamform input[name=naam]');if(i){i.focus();i.select();}">✎</button></h2>
          <form method="post" class="naamform"><input type="hidden" name="actie" value="naam"><input type="hidden" name="p" value="<?= (int)$sel ?>"><input type="hidden" name="inhoud" value="<?= e($inh) ?>">
            <input name="naam" value="<?= e($toonNaam) ?>" style="width:520px;font-size:15px"> <button class="btn dark">Opslaan</button>
            <button type="button" class="btn" onclick="this.closest('.naamvak').classList.remove('bewerk')">Annuleer</button>
            <?php if ($toonNaam !== $autoNaam): ?><span class="muted" style="font-size:12px;margin-left:6px">leeg opslaan = <?= e($autoNaam) ?></span><?php endif; ?></form></div>
        <?php if ($n < drempel('min_aanbieders')): ?><span class="chip" style="background:#fdf0d5;color:#8a4b00;font-size:13px;padding:5px 12px">⚠ Dunne markt: <?= $n ?> aanbieder<?= $n === 1 ? '' : 's' ?>, cijfers zijn nog niet betrouwbaar</span><?php else: ?><span class="muted" style="font-size:13px"><?= $n ?> aanbieders</span><?php endif; ?></div>
      <div class="sv-grid">
        <?php $MGV = marge_per_variant()[(string)($ons['vext'] ?? '')] ?? null; $mgTxt = function ($prijs) use ($MGV) { $pc = g_marge_bij($MGV, $prijs); return $pc === null ? '' : '<div class="pb-mg' . ($pc < g_minv() ? ' laag' : '') . '">' . ($pc < 0 ? 'onder inkoop ' . wn() : 'marge ' . round($pc) . '%') . '</div>'; }; ?>
        <div class="pb-k pb-ons"><small>Prijs <?= wn() ?></small><b class="num"><?= eur($ons['prijs']) ?></b><div class="sv-sub"><?= $gedeeld ? 'gedeeld ' : '' ?><?= $pos ?>e van <?= $n + 1 ?> · <?= $n ? pct(($ons['prijs'] - $med) / $med * 100) . ' vs mediaan' : 'geen markt' ?></div><?= $mgTxt((float)$ons['prijs']) ?></div>
        <div class="pb-k"><small>Laagste prijs</small><b class="num"><?= $n ? eur($p[0]) : '—' ?></b><div class="sv-sub">&nbsp;</div><?= $n ? $mgTxt($p[0]) : '' ?></div>
        <div class="pb-k"><small>Mediaan prijs</small><b class="num"><?= $n ? eur($med) : '—' ?></b><div class="sv-sub">&nbsp;</div><?= $n ? $mgTxt($med) : '' ?></div>
        <div class="pb-k"><small>Hoogste prijs</small><b class="num"><?= $n ? eur($p[$n - 1]) : '—' ?></b><div class="sv-sub">&nbsp;</div><?= $n ? $mgTxt($p[$n - 1]) : '' ?></div>
      </div>
      <?php $bodem = ($MGV && $MGV['inkoop'] !== null) ? $MGV['inkoop'] * (1 + btw_eigen() / 100) : null;
      $lo = $n ? min($p[0], (float)$ons['prijs']) : 0; $hi = $n ? max($p[$n - 1], (float)$ons['prijs']) : 0;
      if ($n && $hi - $lo > 0.005): $sp = $hi - $lo; $x = function ($v) use ($lo, $sp) { return round(($v - $lo) / $sp * 100, 1); }; ?>
      <div class="sv-legenda"><b style="color:var(--ink);font-size:12.5px">Prijsspreiding in de markt</b><span><i class="lg-wij"></i><?= wn() ?></span><span><i class="lg-stip"></i>concurrent</span><span><i class="lg-med"></i>mediaan</span><?= isset($bodem, $lo) && $bodem !== null && $bodem >= $lo ? '<span><i class="lg-bodem"></i>inkoop ' . wn() . ' (incl. btw)</span>' : '' ?><span>links goedkoopst, rechts duurst</span></div>
      <div class="sv-band" title="Spreiding van de markt">
        <div class="sv-lijn"></div>
        <?php foreach ($p as $pv): ?><span class="sv-stip" style="left:<?= $x($pv) ?>%"></span><?php endforeach; ?>
        <span class="sv-wij" style="left:<?= $x((float)$ons['prijs']) ?>%" title="<?= wn() ?>"></span>
        <span class="sv-med" style="left:<?= $x($med) ?>%;top:-3px;height:28px" title="Mediaan"></span>
        <?php if ($bodem !== null && $bodem >= $lo && $bodem <= $hi): ?><span class="sv-bodem" style="left:<?= $x($bodem) ?>%" title="Inkoop <?= wn() ?> incl. btw: <?= eur($bodem) ?>"><i>inkoop</i></span><?php endif; ?>
      </div>
      <div class="sv-as"><span class="num"><?= eur($lo) ?></span><span class="num"><?= eur($hi) ?></span></div>
      <?php if ($bodem !== null): $ruimte = $p[0] - $bodem; ?><div class="bodem-regel">Inkoop <?= wn() ?> incl. btw <b class="num"><?= eur($bodem) ?></b> · <?= $ruimte >= 0 ? 'de laagste prijs in de markt ligt ' . eur($ruimte) . ' daarboven' : '<span style="color:#b42318;font-weight:600">de laagste prijs ligt ' . eur(-$ruimte) . ' ONDER de inkoop van ' . wn() . '</span>' ?></div><?php endif; ?>
      <?php elseif ($n): ?><div class="sv-legenda"><b style="color:var(--ink);font-size:12.5px">Prijsspreiding in de markt</b><span>geen: iedereen rekent dezelfde prijs</span></div>
      <?php endif; ?>
    </div>
    <?php $lijst = [['naam' => $eigen['naam'], 'var' => volle_naam($ons['ptitel'], $ons['vtitel']), 'prijs' => (float)$ons['prijs'], 'wij' => true, 'soort' => '']];
      foreach ($perBron as $bid => $c) $lijst[] = ['naam' => $c['naam'], 'var' => volle_naam($c['ptitel'], $c['vtitel']), 'prijs' => (float)$c['prijs'], 'wij' => false, 'soort' => $c['soort'], 'zeker' => !empty($c['zeker']), 'bid' => $bid, 'vid' => (int)$c['vid'], 'inh' => $c['inhoud_std'], 'ean' => $c['ean'] ?? ''];
      usort($lijst, function ($a, $b) { return $a['prijs'] <=> $b['prijs']; });
      foreach ($keuzes as $bid => $k) if (!isset($perBron[$bid])) $lijst[] = ['naam' => $k['naam'], 'var' => $k['uit'] ? 'niet vergeleken (handmatig gekozen)' : 'geen match gevonden, kies zelf', 'prijs' => null, 'wij' => false, 'soort' => '', 'zeker' => false, 'bid' => $bid, 'vid' => 0]; ?>
    <div class="rk-kop"><span></span><span>Aanbieders</span><span>Prijs incl. btw</span><span>% vs <?= wn() ?></span><span>% vs mediaan</span><span>Match j/n</span></div>
    <?php $rang = 0; foreach ($lijst as $l): if ($l['prijs'] !== null) $rang++; ?>
    <?php if ($l['wij']): ?>
    <details class="rk-det" id="v<?= (int)$ons['vid'] ?>">
      <summary class="rk-rij wij">
        <span class="num muted"><?= $rang ?></span>
        <div style="min-width:0"><b><?= e($l['naam']) ?></b> <?= chip('referentie', 'acc') ?> <span class="chip <?= $KE['door'] === 'jij' ? 'acc' : '' ?>" style="font-size:11px;padding:1px 8px"><?= $KE['door'] === 'jij' ? 'handmatig gekozen' : 'automatisch' ?></span>
          <div class="muted vnaam" style="font-size:13px;margin-top:2px" title="<?= e($l['var']) ?>"><?= e($l['var']) ?> <span class="wijzig">wijzig ›</span></div></div>
        <span class="num ours" style="font-weight:600"><?= eur($l['prijs']) ?></span><span></span>
        <span><?= $med ? pct(($l['prijs'] - $med) / $med * 100, 'wij') : '' ?></span><span></span>
      </summary>
      <form method="post" class="kies"><input type="hidden" name="actie" value="kies_eigen"><input type="hidden" name="p" value="<?= (int)$sel ?>"><input type="hidden" name="inhoud" value="<?= e($inh) ?>">
        <div class="kies-kop">Welke variant van <?= wn() ?> wordt vergeleken voor <?= e(blok_label($inh)) ?>?</div>
        <?php $kop = false; foreach ($KE['kand'] as $c): if (!$c['eigen_product'] && !$kop): $kop = true; ?><div class="kies-kop" style="margin-top:12px">Andere producten van <?= wn() ?> met dezelfde inhoud</div><?php endif; ?>
        <label class="kies-optie"><input type="radio" name="ons" value="<?= (int)$c['vid'] ?>" <?= (int)$c['vid'] === (int)$ons['vid'] ? 'checked' : '' ?> onchange="this.form.submit()">
          <span class="vnaam" style="flex:1"><?= e(volle_naam($c['ptitel'], $c['vtitel'])) ?></span><span class="num"><?= eur($c['prijs']) ?></span></label>
        <?php endforeach; if ($KE['door'] === 'jij'): ?><label class="kies-optie"><input type="radio" name="ons" value="auto" onchange="this.form.submit()"><span style="flex:1" class="muted">Terug naar automatisch</span></label><?php endif; ?>
      </form>
    </details>
    <?php else: $k = $keuzes[$l['bid']]; ?>
    <details class="rk-det">
      <summary class="rk-rij<?= $l['prijs'] === null ? ' uit' : '' ?>">
        <span class="num muted"><?= $l['prijs'] !== null ? $rang : '–' ?></span>
        <div style="min-width:0"><b><?= e($l['naam']) ?></b>
          <?php if ($l['prijs'] !== null && $k['door'] === 'jij'): ?><span class="chip ok" style="font-size:11px;padding:1px 8px">✓ Gecontroleerd</span>
          <?php elseif ($l['prijs'] !== null): ?><span class="chip" style="font-size:11px;padding:1px 8px">Automatisch gekoppeld, niet gecontroleerd</span>
            <form method="post" class="inline" onclick="event.stopPropagation()"><input type="hidden" name="actie" value="kies"><input type="hidden" name="eigen" value="<?= (int)$ons['vid'] ?>"><input type="hidden" name="bron" value="<?= (int)$l['bid'] ?>"><input type="hidden" name="p" value="<?= (int)$sel ?>"><input type="hidden" name="hun" value="<?= (int)$l['vid'] ?>"><button class="mini-knop ok">Klopt</button></form><button type="button" class="mini-knop" onclick="event.preventDefault();event.stopPropagation();this.closest('details').open=true">Andere kiezen</button>
          <?php elseif ($k['uit']): ?><span class="chip ok" style="font-size:11px;padding:1px 8px">✓ Gecontroleerd</span><?php endif; ?>
          <div class="muted vnaam" style="font-size:13px;margin-top:2px" title="<?= e($l['var']) ?>"><?= e($l['var']) ?> <span class="wijzig">wijzig ›</span></div>
          <?php if ($l['prijs'] !== null && $k['door'] !== 'jij'): ?><div class="match-op">Gematcht op <?= ($l['soort'] ?? '') === 'EAN' ? 'EAN ' . e($l['ean']) : 'naam + inhoud' ?>: <b><?= e(korte_titel(explode(' | ', $l['var'])[0])) ?></b> · <?= e(liter($l['inh'])) ?> ↔ <b><?= e(korte_titel($ons['ptitel'])) ?></b> · <?= e(liter($ons['inhoud_std'])) ?></div><?php endif; ?></div>
        <span class="num" style="font-weight:600"><?= $l['prijs'] !== null ? eur($l['prijs']) : '' ?></span>
        <span><?= $l['prijs'] !== null ? pct(($l['prijs'] - $ons['prijs']) / $ons['prijs'] * 100, 'hen') : '' ?></span>
        <span><?= $l['prijs'] !== null && $med ? pct(($l['prijs'] - $med) / $med * 100, 'neutraal') : '' ?></span>
        <span><?= $l['prijs'] !== null ? matchteken($l) : '' ?></span>
      </summary>
      <form method="post" class="kies"><input type="hidden" name="actie" value="kies"><input type="hidden" name="eigen" value="<?= (int)$ons['vid'] ?>"><input type="hidden" name="bron" value="<?= (int)$l['bid'] ?>"><input type="hidden" name="p" value="<?= (int)$sel ?>">
        <div class="kies-kop">Gevonden match voor <b><?= e(volle_naam($ons['ptitel'], $ons['vtitel'])) ?></b> met, bij <?= e($l['naam']) ?>:</div>
        <?php $kl = array_values($k['kand']); usort($kl, function ($a, $b) { return [$a['soort'] === 'handmatig', $a['prijs']] <=> [$b['soort'] === 'handmatig', $b['prijs']]; }); $kop = false;
          foreach ($kl as $c): $on = !$k['uit'] && $l['vid'] === (int)$c['vid'];
          if ($c['soort'] === 'handmatig' && !$kop): $kop = true; ?><div class="kies-kop" style="margin-top:12px">Andere producten van <?= e($l['naam']) ?> met dezelfde inhoud</div><?php endif; ?>
        <label class="kies-optie"><input type="radio" name="hun" value="<?= (int)$c['vid'] ?>" <?= $on ? 'checked' : '' ?> onchange="this.form.submit()">
          <span class="vnaam" style="flex:1"><?= e(volle_naam($c['ptitel'], $c['vtitel'])) ?></span><span class="num"><?= eur($c['prijs']) ?></span>
          <span style="width:120px;text-align:right"><?= $c['soort'] === 'handmatig' ? '<span class="muted" style="font-size:12px">zelfde inhoud</span>' : matchteken($c) ?></span></label>
        <?php endforeach; ?>
        <label class="kies-optie"><input type="radio" name="hun" value="geen" <?= $k['uit'] ? 'checked' : '' ?> onchange="this.form.submit()"><span style="flex:1">Niet vergelijken</span></label>
        <?php if ($k['door'] === 'jij'): ?><label class="kies-optie"><input type="radio" name="hun" value="auto" onchange="this.form.submit()"><span style="flex:1" class="muted">Terug naar automatisch (kleurvariant, anders laagste)</span></label><?php endif; ?>
      </form>
    </details>
    <?php endif; endforeach; ?>
    </div>
  <?php endforeach; endif; endif; ?>

<?php /* ======================= BRONNEN: lijst, en per bron de diepte ======================= */ elseif ($tab === 'concurrenten'):
  $sel = (int)($_GET['bron'] ?? 0);
  $eigenRows = $eigen ? aanbod((int)$eigen['id']) : [];
  $bron = null; if ($sel) { $b = db()->prepare('SELECT * FROM bron WHERE id=?'); $b->execute([$sel]); $bron = $b->fetch(); }
  if (!$bron): /* ---------- lijst van alle bronnen ---------- */ ?>
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:18px"><div><h1>Aanbieders</h1><p class="lead" style="margin:0">Markt <?= e($LAND) ?></p></div>
    <form method="post"><input type="hidden" name="actie" value="alles"><button class="btn dark"<?= $OPEN ? ' disabled style="opacity:.5"' : '' ?>><?= $OPEN ? count($OPEN) . ' bezig…' : 'Alle aanbieders bijwerken' ?></button></form></div>
  <form method="post" class="card" style="display:grid;grid-template-columns:1.6fr 1fr auto;gap:14px;align-items:end"><input type="hidden" name="actie" value="toevoegen">
    <label class="veld">Website<input name="url" required><span class="uitleg">Bijvoorbeeld www.concurrent.nl · de markt volgt uit de extensie (.nl, .be, .de, ...)</span></label>
    <label class="veld">Naam<input name="naam"><span class="uitleg">Optioneel</span></label>
    <div style="padding-bottom:22px"><button class="btn dark" style="padding:11px 20px;font-size:14px" onclick="this.textContent='Zoeken…'">Toevoegen</button></div>
  </form>
  <div class="card flush"><table class="lijst"><tr><th>Aanbieder</th><th>Land</th><th>Platform</th><th>Producten</th><th>Varianten</th><th>Gematcht</th><th>Bijgewerkt</th><th></th></tr>
  <?php foreach ($alle as $b): $t = telling((int)$b['id']); $r = laatste_run((int)$b['id']);
      $n = 0; $g = 0; if (!$b['is_eigen']) foreach (aanbod((int)$b['id']) as $x) { $k = koppel($x, $eigenRows); if ($k) { $n++; $g += ($x['prijs'] - $k[0]['prijs']) / $k[0]['prijs'] * 100; } } ?>
    <tr><td><div class="naamvak"><h2 class="bnaam"><a href="<?= $b['is_eigen'] ? '?tab=shop' : '?tab=concurrenten&bron=' . $b['id'] ?>"><?= e($b['naam']) ?></a> <?= $b['is_eigen'] ? chip('referentie', 'acc') : '' ?><button type="button" class="edit" title="Naam wijzigen" onclick="var v=this.closest('.naamvak');v.classList.add('bewerk');var i=v.querySelector('.naamform input[name=naam]');if(i){i.focus();i.select();}">✎</button></h2>
        <form method="post" class="naamform"><input type="hidden" name="actie" value="bronnaam"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input name="naam" value="<?= e($b['naam']) ?>" style="width:200px;font-size:14px;padding:6px 10px"><button class="btn dark">Opslaan</button><button type="button" class="btn" onclick="this.closest('.naamvak').classList.remove('bewerk')">×</button></form></div>
        <span class="muted" style="font-size:12.5px"><?= e($b['domein']) ?></span></td>
      <td><?php if ($b['is_eigen']): ?><?= e($b['land']) ?><?php else: ?><form method="post" title="Markt van deze aanbieder (volgt uit de extensie; wijzig bij .com e.d.)"><input type="hidden" name="actie" value="bronland"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><select name="land" onchange="this.form.submit()" style="font-size:13px;padding:4px 6px"><?php foreach (array_keys(MARKTEN) as $l): ?><option<?= $l === $b['land'] ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form><?php endif; ?></td><td><?= chip($b['platform'], $b['platform'] === 'lightspeed' ? 'ok' : (strpos($b['platform'], 'geblokkeerd') === 0 ? 'warn' : 'amb')) ?></td>
      <td class="num"><?= $r ? (int)$t['p'] : '—' ?></td><td class="num"><?= $r ? (int)$t['v'] : '—' ?></td>
      <td class="num"><?= $b['is_eigen'] || !$r ? '' : $n . ' var.' ?></td>
      <?php $dk = $r ? json_decode((string)$r['dekking'], true) : null; $opnieuw = $dk && (($dk['route'] ?? '') === 'dubbel') && (!empty($dk['niet_bereikt']) || !$dk['sitemap_urls'] || !$dk['catalogus']); $pcw = in_array($dk['prijscontrole']['markt']['oordeel'] ?? '', ['erg laag', 'lijkt excl. btw', 'opvallend laag'], true);
        $waarom_scan = !$opnieuw ? '' : (!empty($dk['niet_bereikt']) ? count((array)$dk['niet_bereikt']) . ' productpagina\'s reageerden niet, die ontbreken nu' : (!$dk['sitemap_urls'] ? 'hun sitemap was niet leesbaar, dus maar één route bewijst de telling' : 'hun catalogus was niet leesbaar, dus maar één route bewijst de telling'));
        $pm = $dk['prijscontrole']['markt'] ?? []; $waarom_prijs = !$pcw ? '' : 'in de mediaan ' . round(100 * (1 - (float)($pm['mediaan'] ?? 1))) . '% onder die van ' . wn() . ' (' . (int)($pm['n'] ?? 0) . ' vergelijkingen). Scan opnieuw of onderzoek waarom.'; ?>
      <td class="bijgew <?= isset($OPEN[(int)$b['id']]) ? '' : 'num' ?>"><?= isset($OPEN[(int)$b['id']]) ? balk($OPEN[(int)$b['id']]) : e($r['eind'] ?? 'nog niet') . ' ' . (!empty($r['fouten']) ? chip($r['fouten'] . ' fout', 'warn') : '') . ($opnieuw ? '<div class="waarom">' . chip('opnieuw scannen', 'amb') . ' ' . e($waarom_scan) . '</div>' : '') . ($pcw ? '<div class="waarom">' . chip('let op: prijzen erg laag', 'warn') . ' ' . e($waarom_prijs) . '</div>' : '') ?></td>
      <td style="white-space:nowrap;text-align:right">
        <?php if (!isset($OPEN[(int)$b['id']])): ?><form class="inline" method="post" onsubmit="this.querySelector('button').textContent='Bezig, even geduld…'"><input type="hidden" name="actie" value="inventaris"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="tab" value="concurrenten"><button class="btn dark">Bijwerken</button></form><?php endif; ?>
        <?php if (!$b['is_eigen']): ?><form class="inline" method="post" onsubmit="return confirm('<?= e($b['naam']) ?> en al zijn historie verwijderen?')"><input type="hidden" name="actie" value="verwijderen"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="tab" value="concurrenten"><button class="btn">Verwijder</button></form><?php else: ?><button class="btn" style="visibility:hidden">Verwijder</button><?php endif; ?></td></tr>
  <?php endforeach; if (!$alle): ?><tr><td colspan="9" class="muted">Nog geen aanbieders.</td></tr><?php endif; ?>
  </table></div>

<?php else: /* ---------- één bron in de diepte ---------- */
  $rows = aanbod($sel); $r = laatste_run($sel); $prods = []; foreach ($rows as $x) $prods[$x['pid']][] = $x;
  $ean = count(array_filter($rows, function ($x) { return $x['ean'] !== ''; }));
  $vanRows = array_filter($rows, function ($x) { return $x['van_prijs']; });
  $mt = []; $gem = 0; $n = 0; $dl = []; $goedkoper = 0; $duurder = 0; $gelijk = 0; $onzeGekoppeld = [];
  foreach ($rows as $x) { $k = koppel($x, $eigenRows); if ($k) { $mt[$x['vid']] = $k[0]; $d = ($x['prijs'] - $k[0]['prijs']) / $k[0]['prijs'] * 100; $gem += $d; $dl[] = $d; $n++;
      if ($d < -0.5) $goedkoper++; elseif ($d > 0.5) $duurder++; else $gelijk++; foreach ($k as $kk) $onzeGekoppeld[$kk['vid'] ?? ($kk['ean'] . $kk['vtitel'])] = 1; } }
  $medD = g_mediaan($dl);
  $onzeVerf = array_filter($eigenRows, function ($x) { return $x['inhoud_l'] !== null; });
  $nietBijOns = []; foreach ($prods as $vs) { $heeft = false; foreach ($vs as $v) if (isset($mt[$v['vid']])) $heeft = true; if (!$heeft && $vs[0]['inhoud_l'] !== null) $nietBijOns[] = korte_titel($vs[0]['ptitel']); }
  $kortingen = array_map(function ($x) { return (1 - $x['prijs'] / $x['van_prijs']) * 100; }, $vanRows);
  $witKleur = 0; $grp = []; foreach ($rows as $x) if ($x['kleurtype']) $grp[$x['pid'] . '|' . $x['inhoud_std']][$x['kleurtype']] = $x['prijs'];
  foreach ($grp as $g) if (count($g) > 1 && count(array_unique(array_map('strval', $g))) > 1) $witKleur++;
  $lev = count(array_filter($rows, function ($x) { return $x['op_voorraad']; }));
?>
  <p style="margin:0 0 14px"><a href="?tab=concurrenten">← Aanbieders</a></p>
  <div class="card"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
    <div><div style="display:flex;align-items:center;gap:12px"><h1 style="margin:0"><?= e($bron['naam']) ?></h1><?= chip($bron['land']) ?></div>
      </div>
    <div style="display:flex;gap:8px;white-space:nowrap"><form class="inline" method="post" onsubmit="this.querySelector('button').textContent='Bezig, even geduld…'"><input type="hidden" name="actie" value="inventaris"><input type="hidden" name="id" value="<?= $sel ?>"><input type="hidden" name="tab" value="concurrenten"><input type="hidden" name="bron" value="<?= $sel ?>"><button class="btn dark">Bijwerken</button></form>
      <form class="inline" method="post" onsubmit="return confirm('Deze bron en al zijn historie verwijderen?')"><input type="hidden" name="actie" value="verwijderen"><input type="hidden" name="id" value="<?= $sel ?>"><input type="hidden" name="tab" value="concurrenten"><button class="btn">Verwijder</button></form></div></div>
    <?php if (isset($OPEN[$sel])): ?><div style="margin-top:14px"><?= balk($OPEN[$sel]) ?></div><?php endif; ?>
    <div class="stats"><div><small>Platform</small><b><?= e($bron['platform']) ?></b></div><div><small>Aanbod</small><b><?= count($prods) ?> producten · <?= count($rows) ?> var.</b></div>
      <div><small>Gematcht</small><b><?= $n ?> var.</b></div><div><small>% vs <?= wn() ?></small><b><?= $n ? pct($medD, 'hen') : '—' ?></b></div><div><small>Bijgewerkt</small><b><?= e($r['eind'] ?? 'nog niet') ?></b></div></div></div>

  <?= dekking_kaart($r) ?>
  <?php if ($rows): ?>
  <div class="card"><h2 style="margin-bottom:10px">Beoordeling</h2>
    <table>
      <tr><td style="width:220px"><b>Prijs</b></td><td><?= $n ? pct($medD, 'hen') . ' mediaan · ' . $goedkoper . ' goedkoper dan ' . wn() . ' · ' . $gelijk . ' gelijk · ' . $duurder . ' duurder dan ' . wn() : '—' ?></td></tr>
      <tr><td><b>Overlap</b></td><td><?= count($onzeGekoppeld) ?> van de <?= count($onzeVerf) ?> varianten van <?= wn() ?><?= $nietBijOns ? ' · niet bij ' . wn() . ': ' . e(implode(', ', array_slice($nietBijOns, 0, 8))) . (count($nietBijOns) > 8 ? ' …' : '') : '' ?></td></tr>
      <tr><td><b>Wit- en kleurvariant</b></td><td><?= $witKleur ? $witKleur . ' keer een andere prijs' : 'zelfde prijs' ?></td></tr>
      <tr><td><b>Leverbaarheid</b></td><td><?= $lev ?> van <?= count($rows) ?></td></tr>
      <tr><td><b>Data</b></td><td>EAN <?= count($rows) ? round($ean / count($rows) * 100) : 0 ?>%<?= $n ? ' · match via EAN ' . round(count(array_filter($mt, function ($m) { return $m['soort'] === 'EAN'; })) / $n * 100) . '%' : '' ?></td></tr>
    </table></div>
  <?php else: ?><div class="card muted"><?= isset($OPEN[(int)$bron['id']]) ? 'Wordt nu gescand, de producten verschijnen zodra de scan klaar is.' : 'Nog niet gescand. Klik op Bijwerken.' ?></div><?php endif; ?>

  <?php foreach ($prods as $vs): $p = $vs[0]; ?>
  <div class="card flush"><div class="head"><h3><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"><?= e(korte_titel($p['ptitel'])) ?></a></h3><span class="muted" style="font-size:13px"><?= count($vs) ?> varianten</span></div>
    <table><tr><th>Variant</th><th>Inhoud</th><th>Prijs incl. btw</th><th>Per L of kg incl. btw</th><th>% vs <?= wn() ?></th><th>Match j/n</th><th>Levering</th></tr>
    <?php foreach ($vs as $v): $m = $mt[$v['vid']] ?? null; ?>
      <tr><td class="vnaam" title="<?= e(volle_naam($v['ptitel'], $v['vtitel'])) ?>"><?= e(volle_naam($v['ptitel'], $v['vtitel'])) ?></td><td class="num"><?= maat($v) ?><?= eenheid($v) === 'L' && $v['inhoud_l'] != $v['inhoud_std'] ? ' <span class="muted">≈ ' . liter($v['inhoud_std']) . '</span>' : '' ?></td>
        <td class="num"><b><?= eur($v['prijs']) ?></b></td>
        <td class="num muted"><?= $v['inhoud_l'] ? eur($v['prijs'] / $v['inhoud_l']) . '/' . eenheid($v) : '' ?></td>
        <td><?= $m ? pct(($v['prijs'] - $m['prijs']) / $m['prijs'] * 100, 'hen') : '' ?></td>
        <td><?= $m ? matchteken($m) : '<span class="muted">—</span>' ?></td>
        <td><?= $v['op_voorraad'] ? chip('leverbaar', 'ok') : chip('niet leverbaar', 'warn') ?></td></tr>
    <?php endforeach; ?></table></div>
  <?php endforeach; endif; ?>

<?php endif; ?>
<?php if ($OPEN): ?>
<script>
(function(){var runs=<?= json_encode(array_map('intval', array_column(array_values($OPEN), 'id'))) ?>,eerste={},actief=0,klaar=0,MAX=2;
 function toon(run,j){if(j.klaar)j.pct=100;document.querySelectorAll('.runbalk[data-run="'+run+'"]').forEach(function(b){b.querySelector('.rb-tekst span').textContent=j.klaar?'Klaar':j.tekst;b.querySelector('.rb-tekst b').textContent=(j.pct||0)+'%';b.querySelector('.rb-vul').style.width=(j.pct||0)+'%';});}
 function volgende(){while(actief<MAX&&runs.length){actief++;stap(runs.shift());}}
 function stap(run){var f=new FormData();f.append('actie','stap');f.append('run',run);f.append('budget',eerste[run]?20:4);eerste[run]=1;
  fetch(location.pathname,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){toon(run,j);
   if(j.klaar){actief--;klaar++;if(!runs.length&&!actief){setTimeout(function(){location.reload();},600);}else volgende();}else{setTimeout(function(){stap(run);},300);}}).catch(function(){setTimeout(function(){stap(run);},3000);});}
 document.querySelectorAll('.runbalk').forEach(function(b){var id=+b.dataset.run;if(runs.indexOf(id)>2){b.querySelector('.rb-tekst span').textContent='In de wachtrij';}});
 volgende();})();
</script>
<?php endif; ?>
<script>document.querySelectorAll('dialog.pop').forEach(function(d){d.addEventListener('click',function(e){if(e.target===d)d.close();});});</script>
<script>
// Mobiel: tabellen met kolomkoppen krijgen per cel het kolomlabel (kaartweergave via CSS); brede grafieken worden schuifbaar.
(function(){
  document.querySelectorAll('table').forEach(function(t){
    if (t.classList.contains('hm')) return;
    var kop = t.querySelector('tr'); if (!kop) return;
    var th = kop.querySelectorAll('th'); if (th.length < 4 || kop.querySelectorAll('td').length) return;
    var namen = []; th.forEach(function(h){ var n = +(h.getAttribute('colspan') || 1); for (var i = 0; i < n; i++) namen.push(h.textContent.replace(/\s+/g, ' ').trim()); });
    t.classList.add('kt'); kop.classList.add('kt-kop');
    t.querySelectorAll('tr').forEach(function(tr){ if (tr === kop) return; var i = 0;
      tr.querySelectorAll(':scope>td').forEach(function(td){ if (!td.hasAttribute('data-l')) td.setAttribute('data-l', namen[i] || ''); i += +(td.getAttribute('colspan') || 1); }); });
  });
  if (window.innerWidth > 700) return;
  document.querySelectorAll('svg[viewBox]').forEach(function(s){
    var w = +(s.getAttribute('viewBox').split(/[\s,]+/)[2] || 0); if (w < 560 || s.closest('.svg-scroll')) return;
    var d = document.createElement('div'); d.className = 'svg-scroll'; s.parentNode.insertBefore(d, s); d.appendChild(s);
    s.style.width = Math.round(Math.max(560, w * 0.82)) + 'px';
    var h = document.createElement('small'); h.className = 'svg-hint'; h.textContent = 'Veeg opzij voor de hele grafiek →'; d.parentNode.insertBefore(h, d.nextSibling);
  });
  document.querySelectorAll('.hm-wrap').forEach(function(w){ if (w.scrollWidth > w.clientWidth + 4) { var h = document.createElement('small'); h.className = 'hm-hint'; h.textContent = 'Veeg opzij voor alle aanbieders →'; w.parentNode.insertBefore(h, w.nextSibling); } });
})();
</script>
</main></body></html>

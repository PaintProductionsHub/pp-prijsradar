<?php
// Prijsradar: volledige scan voor WooCommerce, Magento, BigCommerce, PrestaShop en onbekende shops.
// Principe: twee onafhankelijke routes naar de producten (1: sitemap, 2: platformcatalogus of zoekfunctie),
// daarna elke kandidaat-pagina lezen en per variant de verkoopprijs bij 1 stuk incl. btw vastleggen.

// Merk + productlijnen uit config.php ('merk_lijnen'): vangen producten waarvan de naam het merk niet noemt.
function merk_lijnen(): string {
    global $CFG;
    $l = trim((string)($CFG['merk_lijnen'] ?? ''), '|');
    return preg_quote(strtolower(merk()), '/') . '(?![a-z])' . ($l !== '' ? '|' . $l : ''); // merk als los woord: "keim" niet in "keimfrei"
}
function is_merk(string $tekst): bool { return (bool)preg_match('/(?<![a-z0-9])(' . merk_lijnen() . ')/i', $tekst); } // op een woordbegin
// Btw-vermelding op een pagina (NL, DE, EN): 'excl' of 'incl'.
function btw_vermeld(string $tekst, string $soort): bool {
    $w = $soort === 'excl' ? 'excl\\.?|exkl\\.?|exclusief|zzgl\\.?|ohne|excluding' : 'incl\\.?|inkl\\.?|inclusief|inklusive|including';
    return (bool)preg_match('/(?:' . $w . ')\\s*(?:\\d+\\s*%\\s*)?(?:btw|vat|mwst|ust)/i', $tekst);
}
// EAN/GTIN uit gestructureerde productdata (JSON-LD), ook uit de offer.
function gtin(array $x): string {
    foreach ([$x, (array)(isset($x['offers'][0]) ? $x['offers'][0] : ($x['offers'] ?? []))] as $d)
        foreach (['gtin13', 'gtin', 'gtin14', 'gtin12', 'gtin8', 'ean'] as $k) if (!empty($d[$k]) && is_scalar($d[$k])) return preg_replace('/\\D/', '', (string)$d[$k]);
    return '';
}

// HTTP met eind-URL, POST en extra headers.
function haal_x(string $url, ?int &$code = null, ?string &$eind = null, ?string $post = null, array $hdr = []): ?string {
    global $CFG;
    $ch = curl_init(url_ascii($url));
    $h = array_merge(['Accept-Language: ' . ($CFG['http_taal'] ?? 'nl-NL,nl;q=0.9'), 'Accept: text/html,application/json,application/xml;q=0.9,*/*;q=0.8'], $hdr);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => stap_timeout(20),
          CURLOPT_USERAGENT => $CFG['user_agent'], CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $h];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $post; }
    curl_setopt_array($ch, $o);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $eind = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (($code === 0 || $code === 429 || $code === 503) && stap_rest() > 14) { sleep(3); curl_setopt($ch, CURLOPT_TIMEOUT, stap_timeout(20)); $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $eind = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); }
    curl_close($ch);
    usleep((int)(($CFG['pauze_sec'] ?? 1.0) * 0.5e6));
    if ($body === false || $code < 200 || $code >= 300) return null;
    if (substr($url, -3) === '.gz' && substr($body, 0, 2) === "\x1f\x8b") $body = (string)@gzdecode($body);
    return $body;
}

function pad_van(string $u): string { $p = parse_url($u); return rtrim(strtolower($p['path'] ?? '/'), '/'); }

// ---------- routes naar productpagina's ----------
function sitemap_start(string $home): array {
    $q = [];
    $r = haal_x($home . 'robots.txt', $c);
    if ($r && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $r, $m)) $q = $m[1];
    if (!$q) foreach (['sitemap_index.xml', 'sitemap.xml', 'wp-sitemap.xml', 'xmlsitemap.php', 'product-sitemap.xml'] as $p) {
        $x = haal_x($home . $p, $c); if ($x && stripos($x, '<loc>') !== false) { $q[] = $home . $p; break; }
    }
    return array_values(array_unique($q));
}
// verwerkt één sitemap uit de wachtrij; indexen voegen kinderen toe (productsitemaps eerst, alleen die als ze bestaan)
function sitemap_stap(array &$st): void {
    $u = array_shift($st['sm_q']); if (!$u) return;
    if (isset($st['sm_gezien'][$u])) return; $st['sm_gezien'][$u] = 1;
    $x = haal_x($u, $c); if (!$x) { $st['log'][] = "sitemap niet leesbaar: $u"; return; }
    if (stripos($x, '<sitemapindex') !== false) {
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $x, $m);
        $kind = array_map('html_entity_decode', $m[1]);
        $prod = array_values(array_filter($kind, function ($k) { return preg_match('/product/i', $k); }));
        foreach (($prod ?: $kind) as $k) if (!preg_match('/(image|video|post-sitemap|page-sitemap|category|categor|tag|author|blog|cms)/i', $k) || $prod) $st['sm_q'][] = $k;
        return;
    }
    // urlset: per <url> de loc + eventuele afbeeldingstitels
    preg_match_all('#<url>(.*?)</url>#is', $x, $blokken);
    foreach ($blokken[1] as $b) {
        if (!preg_match('#<loc>\s*([^<\s]+)\s*</loc>#i', $b, $l)) continue;
        $loc = html_entity_decode($l[1]);
        preg_match_all('#<image:(?:title|caption)>(.*?)</image:(?:title|caption)>#is', $b, $t);
        $tekst = strtolower($loc . ' ' . implode(' ', $t[1]));
        $st['sm_n']++;
        $hh = preg_replace('/^www\./', '', host_ascii((string)parse_url($loc, PHP_URL_HOST)));
        if ($hh !== preg_replace('/^www\./', '', host_ascii($st['host'] ?? $hh))) continue; // ook bij domeinen met umlaut (xn--)
        if (preg_match('#/(product-tag|product-category|tag|categorie|category|merken|brands?)/#i', $loc)) continue;
        if (is_merk($tekst)) $st['kand'][pad_van($loc)] = ['url' => $loc, 'via' => ($st['kand'][pad_van($loc)]['via'] ?? '') . 'S'];
    }
}

// Route 2: de eigen catalogus of zoekfunctie van het platform. Geeft kandidaten + (waar mogelijk) het totaal van de shop.
function native_stap(array $bron, array &$st): bool {
    $home = 'https://' . $bron['domein'] . '/'; $pl = $bron['platform']; $mk = strtolower(merk());
    if ($pl === 'woocommerce') {
        $p = $st['n_page'];
        $b = haal_x($home . "wp-json/wc/store/v1/products?per_page=100&page=$p", $c);
        $rij = $b ? json_decode($b, true) : null;
        if (!is_array($rij)) { $st['log'][] = 'WooCommerce Store API niet leesbaar (HTTP ' . $c . ')'; return true; }
        foreach ($rij as $x) {
            $st['n_n']++;
            $tekst = strtolower(($x['name'] ?? '') . ' ' . json_encode($x['categories'] ?? []) . ' ' . json_encode($x['brands'] ?? []) . ' ' . json_encode($x['attributes'] ?? []) . ' ' . json_encode($x['tags'] ?? []));
            if (is_merk($tekst) && !empty($x['permalink'])) {
                $k = pad_van($x['permalink']);
                $st['kand'][$k] = ['url' => $x['permalink'], 'via' => ($st['kand'][$k]['via'] ?? '') . 'N', 'merk' => 1];
            }
        }
        $st['n_page']++;
        return count($rij) < 100;
    }
    if ($pl === 'magento') {
        $p = $st['n_page'];
        $q = json_encode(['query' => '{products(search:"' . $mk . '",pageSize:50,currentPage:' . $p . '){total_count items{name url_key url_suffix canonical_url}}}']);
        $b = haal_x($home . 'graphql', $c, $e, $q, ['Content-Type: application/json', 'Store: default']);
        $d = $b ? json_decode($b, true) : null; $items = $d['data']['products']['items'] ?? null;
        if (!is_array($items)) {
            // terugval: zoekpagina (HTML)
            $b = haal_x($home . 'catalogsearch/result/?q=' . $mk . '&product_list_limit=100&p=' . $p, $c);
            if (!$b) { $st['log'][] = 'Magento zoeken niet leesbaar'; return true; }
            preg_match_all('#class="[^"]*product-item-link[^"]*"[^>]*href="([^"]+)"|href="([^"]+)"[^>]*class="[^"]*product-item-link#i', $b, $m);
            $urls = array_filter(array_merge($m[1], $m[2]));
            foreach ($urls as $u) { $k = pad_van($u); $st['kand'][$k] = ['url' => html_entity_decode($u), 'via' => ($st['kand'][$k]['via'] ?? '') . 'N']; }
            $st['n_n'] += count($urls); $st['n_page']++;
            return count($urls) < 100 || $p > 10;
        }
        $st['n_opgave'] = (int)$d['data']['products']['total_count'];
        foreach ($items as $x) {
            $st['n_n']++;
            if (!is_merk(($x['name'] ?? '') . ' ' . ($x['url_key'] ?? ''))) continue; // zoekruis (ander merk)
            $u = $x['canonical_url'] ?? ''; if ($u === '' || $u === null) $u = ($x['url_key'] ?? '') . ($x['url_suffix'] ?? '.html');
            if (!preg_match('#^https?://#', $u)) $u = $home . ltrim($u, '/');
            $k = pad_van($u); $st['kand'][$k] = ['url' => $u, 'via' => ($st['kand'][$k]['via'] ?? '') . 'N'];
        }
        $st['n_page']++;
        return $p * 50 >= $st['n_opgave'];
    }
    if ($pl === 'shopify') {
        // Shopify: de openbare catalogus (/products.json) geeft per product alle varianten met prijs; productpagina's hoeven dan niet meer gelezen.
        $p = $st['n_page'];
        $b = haal_x($home . "products.json?limit=250&page=$p", $c); $rij = $b ? (json_decode($b, true)['products'] ?? null) : null;
        if (!is_array($rij)) { $st['log'][] = 'Shopify-catalogus niet leesbaar (HTTP ' . $c . ')'; return true; }
        foreach ($rij as $x) {
            $st['n_n']++;
            if (!is_merk(($x['title'] ?? '') . ' ' . ($x['vendor'] ?? '') . ' ' . ($x['product_type'] ?? '') . ' ' . implode(' ', (array)($x['tags'] ?? [])))) continue;
            $u = $home . 'products/' . $x['handle']; $k = pad_van($u); $vs = [];
            foreach (($x['variants'] ?? []) as $v) if (($pr = (float)($v['price'] ?? 0)) > 0)
                $vs[] = ['titel' => ($v['title'] ?? '') === 'Default Title' ? 'standaard' : tekst($v['title']), 'prijs' => $pr, 'voorraad' => !empty($v['available']) ? 1 : 0, 'ext' => (string)$v['id'], 'ean' => (string)($v['barcode'] ?? '')];
            if ($vs) $st['prod'][$k] = ['url' => $u, 'via' => ($st['kand'][$k]['via'] ?? '') . 'N', 'naam' => tekst($x['title']), 'merk' => (string)($x['vendor'] ?? ''), 'varianten' => $vs, 'kinderen' => [], 'controle' => ['n' => count($vs)]];
        }
        $st['n_page']++;
        return count($rij) < 250 || $p >= 100;
    }
    if ($pl === 'bigcommerce') {
        if (empty($st['bc_token'])) {
            $h = haal_x($home, $c) ?? '';
            if (preg_match('/Bearer (eyJ[A-Za-z0-9._-]+)/', $h, $m) || preg_match('/storefrontApiToken\\\\?"\s*:\s*\\\\?"(eyJ[^"\\\\]+)/', $h, $m) || preg_match('/"(eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,})"/', $h, $m)) $st['bc_token'] = $m[1];
            else { $st['log'][] = 'BigCommerce: storefront-sleutel niet gevonden'; return true; }
        }
        $q = json_encode(['query' => 'query($a:String){site{products(first:50,after:$a){pageInfo{hasNextPage endCursor} edges{node{entityId name path brand{name}}}}}}', 'variables' => ['a' => $st['bc_na'] ?? null]]);
        $b = haal_x($home . 'graphql', $c, $e, $q, ['Content-Type: application/json', 'Authorization: Bearer ' . $st['bc_token'], 'Origin: ' . rtrim($home, '/')]);
        $d = $b ? json_decode($b, true) : null; $p = $d['data']['site']['products'] ?? null;
        if (!$p) { $st['log'][] = 'BigCommerce catalogus niet leesbaar'; return true; }
        foreach ($p['edges'] as $e2) { $x = $e2['node']; $st['n_n']++;
            if (is_merk(($x['name'] ?? '') . ' ' . ($x['brand']['name'] ?? ''))) { $u = rtrim($home, '/') . $x['path']; $k = pad_van($u); $st['kand'][$k] = ['url' => $u, 'via' => ($st['kand'][$k]['via'] ?? '') . 'N', 'bc_id' => (int)$x['entityId'], 'merk' => 1]; } }
        $st['bc_na'] = $p['pageInfo']['endCursor'];
        return !$p['pageInfo']['hasNextPage'];
    }
    if ($pl === 'prestashop' || $pl === 'onbekend') {
        // merkpagina + zoekfunctie, gepagineerd
        $p = $st['n_page'];
        if ($p === 1 && empty($st['ps_merk'])) {
            $h = haal_x($home, $c) ?? '';
            if (preg_match('#href="([^"]*/\d+_' . preg_quote($mk, '#') . '[^"]*)"#i', $h, $m)) $st['ps_merk'] = html_entity_decode($m[1]);
        }
        $urls = [];
        foreach (array_filter([$st['ps_merk'] ?? null, $home . 'index.php?controller=search&search_query=' . $mk . '&n=100', $home . 'zoeken?search_query=' . $mk . '&n=100', $home . '?s=' . $mk]) as $bron_url) {
            $b = haal_x($bron_url . (strpos($bron_url, '?') === false ? '?' : '&') . 'p=' . $p, $c); if (!$b) continue;
            preg_match_all('#<a[^>]+class="[^"]*(?:product-name|product_img_link|product-title)[^"]*"[^>]+href="([^"]+)"|<a[^>]+href="([^"]+)"[^>]+class="[^"]*(?:product-name|product_img_link)[^"]*"#i', $b, $m);
            foreach (array_filter(array_merge($m[1], $m[2])) as $u) { $u = preg_replace('/\?.*$/', '', html_entity_decode($u)); $urls[pad_van($u)] = $u; }
        }
        foreach ($urls as $k => $u) { if (strpos($u, 'http') !== 0) $u = rtrim($home, '/') . '/' . ltrim($u, '/'); $st['kand'][$k] = ['url' => $u, 'via' => ($st['kand'][$k]['via'] ?? '') . 'N']; }
        $st['n_n'] += count($urls); $st['n_page']++;
        return count($urls) < 10 || $p >= 5;
    }
    return true;
}

// ---------- één productpagina lezen ----------
function jsonld(string $h): array {
    $uit = [];
    preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $h, $m);
    foreach ($m[1] as $j) { $d = json_decode(trim($j), true); if ($d === null) $d = json_decode(html_entity_decode(trim($j)), true); if ($d !== null) $uit[] = $d; }
    $plat = [];
    $loop = function ($x) use (&$loop, &$plat) {
        if (!is_array($x)) return;
        if (isset($x['@type'])) $plat[] = $x;
        foreach ($x as $v) if (is_array($v)) $loop($v);
    };
    foreach ($uit as $d) $loop($d);
    return $plat;
}
// Prijs uit tekst, NL/DE en EN notatie: "1.234,56 €", "12,9", "1,234.56", "1.234" (duizendtal).
function prijsgetal($s): ?float {
    if ($s === null || $s === '') return null; if (is_numeric($s)) return (float)$s;
    $s = preg_replace('/[^\d,.]/', '', (string)$s); $k = strrpos($s, ','); $p = strrpos($s, '.');
    if ($k !== false && $p !== false) $s = $k > $p ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
    elseif ($k !== false) $s = preg_match('/,\d{1,2}$/', $s) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
    elseif ($p !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    return is_numeric($s) ? (float)$s : null;
}
function json_na(string $h, string $sleutel): ?array {
    $i = strpos($h, '"' . $sleutel . '"'); if ($i === false) return null;
    $j = strpos($h, '{', $i); if ($j === false) return null;
    $diep = 0; $in = false; $esc = false; $n = strlen($h);
    for ($k = $j; $k < $n; $k++) { $c = $h[$k];
        if ($in) { if ($esc) $esc = false; elseif ($c === '\\') $esc = true; elseif ($c === '"') $in = false; }
        elseif ($c === '"') $in = true; elseif ($c === '{') $diep++;
        elseif ($c === '}') { $diep--; if ($diep === 0) { $d = json_decode(substr($h, $j, $k - $j + 1), true); return is_array($d) ? $d : null; } } }
    return null;
}
function json_vanaf(string $h, int $j): ?array {
    $j = strpos($h, '{', $j); if ($j === false) return null;
    $diep = 0; $in = false; $esc = false; $n = strlen($h);
    for ($k = $j; $k < $n; $k++) { $c = $h[$k];
        if ($in) { if ($esc) $esc = false; elseif ($c === '\\') $esc = true; elseif ($c === '"') $in = false; }
        elseif ($c === '"') $in = true; elseif ($c === '{') $diep++;
        elseif ($c === '}') { $diep--; if ($diep === 0) { $d = json_decode(substr($h, $j, $k - $j + 1), true); return is_array($d) ? $d : null; } } }
    return null;
}
function tekst($s): string { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$s), ENT_QUOTES, 'UTF-8'))); }

// ---------- maat (inhoud) van de getoonde prijs ----------
// Shops als Shopware zetten de maat niet in de naam maar in het prijsblok ("Inhalt: 0.75 Liter (66,65 € / 1 Liter)"),
// als Grundpreis ("50,67 € pro Liter") of alleen in een maatkeuze. Zonder maat valt een variant uit de vergelijking.
function maat_label(float $v, bool $kg): string { return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',') . ($kg ? ' kg' : ' Liter'); }
// hoeveelheid naar liters of kilo's: [waarde, is_kg] of null
function maat_naar_basis(float $n, string $u): ?array {
    $u = strtolower(rtrim($u, '.'));
    if (in_array($u, ['l', 'lt', 'ltr', 'liter', 'litre', 'liters'], true)) return [$n, false];
    if ($u === 'ml') return [$n / 1000, false];
    if (in_array($u, ['kg', 'kilogramm', 'kilogram'], true)) return [$n, true];
    if (in_array($u, ['g', 'gr', 'gramm', 'gram'], true)) return [$n / 1000, true];
    return null;
}
// Afronden naar een gangbare verpakkingsmaat (binnen 3%); anders null: dan liever geen maat dan een verzonnen maat.
function std_maat(float $v): ?float {
    $best = null;
    foreach ((array)($GLOBALS['CFG']['standaardmaten'] ?? [0.5, 0.68, 0.75, 0.9, 1, 2, 2.5, 2.7, 3, 4, 4.5, 5, 9, 10, 20, 25]) as $s)
        if (abs($v - $s) / $s <= 0.03 && ($best === null || abs($v - $s) < abs($v - $best))) $best = (float)$s;
    return $best;
}
// Tekst direct na de hoofdprijs (niet de cross-sell verderop op de pagina). Eerste anker dat bestaat wint.
function prijs_venster(string $h): string {
    foreach (['/class="[^"]*\bproduct-detail-price-container\b/', "/class=['\"][^'\"]*\bproduct--price price--default\b/", '/class="[^"]*\bproduct--buybox\b/',
              '/itemprop="price"/i', '/class="[^"]*\b(?:product-info-price|price-box)\b/', '/<p class="price\b/'] as $re)
        if (preg_match($re, $h, $m, PREG_OFFSET_CAPTURE)) {
            $x = substr($h, $m[0][1], 12000);
            $x = preg_replace(['#<!--.*?-->#s', '#<(script|style)\b.*?</\1>#is'], ' ', $x);
            $x = tekst(preg_replace('/<[^>]*>/', ' ', preg_replace('/^[^>]*>/', '', $x)));
            return mb_substr($x, 0, 450);
        }
    return '';
}
// Maat van de getoonde prijs: ['label' => '0,75 Liter', 'bron' => 'keuze|inhalt|grundpreis'] of null.
// Volgorde: (1) de gekozen optie in de maatkeuze: die bepaalt welke variant de shop toont (een Grundpreis kan per variant
// verkeerd onderhouden zijn: holz-wohnen-garten toont bij 3 ltr "149,00 € pro Liter"); (2) "Inhalt" bij de hoofdprijs; (3) Grundpreis.
// Heeft de pagina een maatkeuze zonder keuze (een "ab"-prijs), dan moet (2)/(3) één van de aangeboden maten zijn.
function inhoud_bij_prijs(string $h, float $prijs, ?array $keuze, ?array $offer = null): ?array {
    $mag = null;
    if ($keuze) { $mag = [];
        foreach ($keuze['opties'] as $o) { [$l, $std] = inhoud($o['label']); if ($l === null) continue; $mag[] = (string)$std;
            if ($o['sel']) return ['label' => maat_label($l, $std >= 10000), 'bron' => 'keuze']; } }
    $ok = function (string $label) use ($mag) { return $mag === null || in_array((string)inhoud($label)[1], $mag, true); };
    // JSON-LD UnitPriceSpecification (o.a. Gambio): prijs per referenceQuantity. Is die prijs de verkoopprijs, dan is referenceQuantity de maat.
    $ps = $offer['priceSpecification'] ?? null; if (isset($ps[0])) $ps = $ps[0];
    if (is_array($ps) && $prijs > 0 && ($rq = $ps['referenceQuantity'] ?? null) && ($pp = prijsgetal($ps['price'] ?? null))) {
        $eh = strtoupper(trim((string)($rq['unitCode'] ?? ''))); $eh = ['LTR' => 'l', 'MLT' => 'ml', 'KGM' => 'kg', 'GRM' => 'g'][$eh] ?? (preg_match('/^\s*(ml|liter|litre|ltr|l|kg|kilogramm|gramm|g)\b/i', (string)($rq['unitText'] ?? ''), $um) ? $um[1] : '');
        $rb = $eh !== '' ? maat_naar_basis((float)($rq['value'] ?? 0), $eh) : null;
        if ($rb && $rb[0] > 0) { $s = abs($pp - $prijs) < 0.01 ? $rb[0] : $prijs / $pp * $rb[0]; $s2 = std_maat($s) ?? (abs($pp - $prijs) < 0.01 ? $s : null);
            if ($s2 !== null && $ok($lb = maat_label($s2, $rb[1]))) return ['label' => $lb, 'bron' => 'jsonld']; }
    }
    $t = prijs_venster($h);
    // het venster hoort alleen bij de hoofdprijs als die prijs er vooraan in staat (anders: ander product, minicart, cross-sell)
    // en alleen tot de eerstvolgende andere prijs die geen prijs per eenheid is ("66,65 € / 1 Liter", "50,67 € pro Liter" horen er wel bij)
    $vooraan = false; $van = 0;
    if ($prijs > 0 && preg_match_all('/(?:€|eur)\s*\K\d{1,3}(?:[.\s]?\d{3})*[.,]\d{2}|\d{1,3}(?:[.\s]?\d{3})*[.,]\d{2}(?=\s*(?:€|eur))/iu', $t, $pm, PREG_OFFSET_CAPTURE)) { // alleen bedragen met €
        foreach ($pm[0] as $x) {
            if (!$vooraan) { if ($x[1] <= 200 && abs((float)prijsgetal($x[0]) - $prijs) < 0.011) { $vooraan = true; $van = $x[1] + strlen($x[0]); } continue; }
            if (!preg_match('/^\s*(?:€|eur)?\s*\*?\s*(?:\/|pro|je|per)\s*(?:\d+(?:[.,]\d+)?\s*)?(?:ml|liter|litre|ltr|lt|l|kg|g)(?![\p{L}])/iu', substr($t, $x[1] + strlen($x[0]), 40))) { $t = substr($t, 0, $x[1]); break; }
        }
        $t = substr($t, $van);
    }
    if ($t !== '' && $vooraan) {
        // (a) "Inhalt: 0.75 Liter (66,65 € / 1 Liter)". Staat er een prijs per eenheid bij, dan moet die kloppen met de hoofdprijs (anders hoort het label bij iets anders).
        $u = 'ml|liters?|litre|ltr\.?|lt|l|kilogramm|kg|gramm|gr|g';
        if (preg_match_all('/(?<!\p{L})(?:inhalt|inhoud|content|füllmenge|inh\.)\s*:?\s*(\d+(?:[.,]\d+)?)\s*(' . $u . ')(?![\p{L}])(?:\s*\(\s*([\d.,]+)\s*(?:€|eur)?\s*\*?\s*\/\s*(\d+(?:[.,]\d+)?)?\s*(' . $u . ')(?![\p{L}]))?/iu', $t, $mm, PREG_SET_ORDER))
            foreach ($mm as $m) {
                $b = maat_naar_basis((float)str_replace(',', '.', $m[1]), $m[2]); if (!$b || $b[0] <= 0) continue;
                if (!empty($m[3]) && !empty($m[5])) {
                    $ref = prijsgetal($m[3]); $rb = maat_naar_basis((float)str_replace(',', '.', ($m[4] ?? '') !== '' ? $m[4] : '1'), $m[5]);
                    if (!$ref || !$rb || $rb[1] !== $b[1] || abs($prijs / $b[0] * $rb[0] - $ref) / $ref > 0.03) continue;
                }
                if ($ok($lb = maat_label($b[0], $b[1]))) return ['label' => $lb, 'bron' => 'inhalt'];
            }
        // (b) Grundpreis: prijs ÷ prijs per liter/kilo, afgerond op een gangbare maat
        if (preg_match_all('/((?:\d{1,3}(?:\.\d{3})*|\d+),\d{2}|\d+\.\d{2})\s*(?:€|eur)\s*\*?\s*(?:\/|pro|je|per)\s*(\d+(?:[.,]\d+)?)?\s*(ml|liter|litre|ltr|lt|l|kg|g)(?![\p{L}])/iu', $t, $mm, PREG_SET_ORDER))
            foreach ($mm as $m) {
                $gp = prijsgetal($m[1]); $rb = maat_naar_basis((float)str_replace(',', '.', ($m[2] ?? '') !== '' ? $m[2] : '1'), $m[3]);
                if (!$gp || !$rb || $rb[0] <= 0) continue;
                $s = std_maat($prijs / $gp * $rb[0]); if ($s !== null && $ok($lb = maat_label($s, $rb[1]))) return ['label' => $lb, 'bron' => 'grundpreis'];
            }
    }
    return null;
}
// Maatkeuze op de pagina. Shopware 5: <select name="group[9]"> of radio's name="group[9]"; Shopware 6: radio's per groep-id + switch-URL.
// Alleen een groep waarvan elke optie een maat is (0,75 Liter / 3 ltr. / 10 kg) telt. Geeft null of
// ['soort' => 'sw5'|'sw6', 'groep' => naam, 'opties' => [['w','label','sel','uit']], 'anders' => [groep => gekozen waarde], 'switch' => url|null].
function maatkeuze(string $h): ?array {
    $g = []; $sw6 = false;
    preg_match_all('#<select\b([^>]*)\bname="(group\[\d+\]|[0-9a-f]{32})"([^>]*)>(.*?)</select>#is', $h, $sel, PREG_SET_ORDER);
    foreach ($sel as $s) { preg_match_all('#<option\b([^>]*)>(.*?)</option>#is', $s[4], $oo, PREG_SET_ORDER);
        foreach ($oo as $o) { if (!preg_match('/\bvalue="([^"]*)"/', $o[1], $v) || $v[1] === '') continue;
            $g[$s[2]][$v[1]] = ['w' => $v[1], 'label' => tekst($o[2]), 'sel' => (bool)preg_match('/\bselected\b/i', $o[1]), 'uit' => (bool)preg_match('/\bdisabled\b/i', $o[1])]; } }
    preg_match_all('#<input\b[^>]*type="radio"[^>]*>#i', $h, $rad);
    foreach ($rad[0] as $r) {
        if (!preg_match('/\bname="(group\[\d+\]|[0-9a-f]{32})"/', $r, $n) || !preg_match('/\bvalue="([^"]+)"/', $r, $v)) continue;
        $lab = preg_match('/\btitle="([^"]*)"/', $r, $t) ? $t[1] : '';
        if (preg_match('/\bid="([^"]+)"/', $r, $id) && preg_match('#<label\b[^>]*for="' . preg_quote($id[1], '#') . '"[^>]*>(.*?)</label>#is', $h, $l)) {
            if (preg_match('/\btitle="([^"]*)"/', $l[0], $t)) $lab = $lab ?: $t[1]; $lab = $lab ?: $l[1]; }
        if (strlen($n[1]) === 32) $sw6 = true;
        $g[$n[1]][$v[1]] = ['w' => $v[1], 'label' => tekst($lab), 'sel' => (bool)preg_match('/\bchecked\b/i', $r), 'uit' => (bool)preg_match('/\bdisabled\b|is-disabled/i', $r)];
    }
    $maat = null; $anders = []; $andersLabel = [];
    foreach ($g as $naam => $opties) {
        $alleMaat = true; foreach ($opties as $o) if (inhoud($o['label'])[0] === null) { $alleMaat = false; break; }
        if ($alleMaat && $maat === null) $maat = $naam;
        else foreach ($opties as $o) if ($o['sel']) { $anders[$naam] = $o['w']; if (mb_strlen($o['label']) <= 40 && inhoud($o['label'])[0] === null) $andersLabel[] = $o['label']; }
    }
    if ($maat === null) return null;
    $switch = null;
    if ($sw6 && preg_match('/data-variant-switch-options="([^"]*)"/', $h, $m) && ($d = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)) && !empty($d['url'])) $switch = (string)$d['url'];
    // 'extra': gekozen opties van andere groepen (bv. kleur "Weiß" / "Mix") voor in de varianttitel, zodat kleurtype() werkt
    return ['soort' => strlen($maat) === 32 ? 'sw6' : 'sw5', 'groep' => $maat, 'opties' => array_values($g[$maat]), 'anders' => $anders, 'extra' => implode(' / ', $andersLabel), 'switch' => $switch];
}
// Productfamilie: naam zonder kleur ("DEMIDEKK Cleantech (RAL 1000 Grünbeige)" en "(Weiß)" zijn één familie). Alleen voor kostenbewaking, nooit om prijzen te delen.
function familie(string $naam): string { return naamsleutel(preg_replace(['/\([^)]*\)/', '/\b(?:ral|ncs)\s*[a-z0-9-]*\d{3,}[a-z0-9-]*/i'], ' ', $naam)); }
// Id van de getoonde variant (Shopware: productID/sku in microdata), om dubbele URL's van dezelfde variant te herkennen.
function pagina_id(string $h, string $sku = ''): string {
    if (preg_match('/itemprop="productID"[^>]*content="([^"]+)"/', $h, $m)) return $m[1];
    if (preg_match('/itemprop="sku"[^>]*>\s*([^<\s][^<]*?)\s*</', $h, $m) || preg_match('/itemprop="sku"[^>]*content="([^"]+)"/', $h, $m)) return tekst($m[1]);
    return $sku;
}

// Geeft ['naam','merk','merkok'=>bool,'varianten'=>[['titel','prijs','voorraad','ext']], 'kinderen'=>[paden van losse variantpagina's]] of null
function lees_pagina(string $platform, string $h, string $url, array $kand, array &$st): ?array {
    $ld = jsonld($h); $prod = null; $groep = null; $kruimel = '';
    foreach ($ld as $x) { $t = (array)($x['@type'] ?? []);
        if (in_array('ProductGroup', $t, true)) { if (!$groep || (empty($groep['hasVariant']) && !empty($x['hasVariant']))) $groep = $x; } elseif (in_array('Product', $t, true) && !$prod) $prod = $x;
        if (in_array('BreadcrumbList', $t, true)) $kruimel .= ' ' . json_encode($x); }
    $hoofd = $groep ?: $prod;
    $body = preg_match('#<body[^>]*class="([^"]*)"#i', $h, $bm) ? $bm[1] : '';
    if (preg_match('/catalog-category-view|tax-product_cat|post-type-archive|page-products|category-page|brand-page/i', $body)) return ['skip' => 'overzichtspagina'];
    $isProduct = $hoofd || !empty($kand['bc']) || stripos($h, 'productView') !== false || preg_match('/catalog-product-view|single-product|product-template|type-product|page-product/i', $body) || preg_match('#<body[^>]*id="product"#i', $h)
        || preg_match('#itemtype="https?://schema\.org/Product"|property="og:type"\s+content="product#i', $h); // o.a. Shopware (microdata)
    if (!$isProduct) return ['skip' => 'geen productpagina'];

    $naam = tekst($hoofd['name'] ?? '');
    if ($naam === '' && preg_match('#<h1[^>]*>(.*?)</h1>#is', $h, $m)) $naam = tekst($m[1]);
    if ($naam === '' && preg_match('#property="og:title"\s+content="([^"]+)"#i', $h, $m)) $naam = tekst($m[1]);
    $merk = $hoofd['brand']['name'] ?? ($hoofd['brand'] ?? ($prod['brand']['name'] ?? ''));
    if (is_array($merk)) $merk = $merk['name'] ?? '';
    // Is dit een product van ons merk? Naam, merkveld of productlijn; anders merk-labels op de pagina (NL/DE/EN).
    $mk = preg_quote(strtolower(merk()), '/');
    $merkok = is_merk($naam . ' ' . $merk) || !empty($kand['merk'])
        || preg_match('/(product_cat|pa_merk|pa_marke|pa_brand|product_brand|pwb-brand|brand)-[a-z0-9-]*' . $mk . '/i', substr($h, 0, 400000))
        || stripos($kruimel, strtolower(merk())) !== false
        || preg_match('/>\s*(Merk|Brand|Fabrikant|Marke|Hersteller)\s*<.{0,300}?>\s*' . $mk . '\s*</is', $h);
    $vars = []; $kinderen = [];
    if ($platform === 'woocommerce' && preg_match('#data-product_variations="([^"]*)"#', $h, $m)) {
        $vv = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $labels = [];
        preg_match_all('#<select[^>]*name="attribute_([^"]+)"[^>]*>(.*?)</select>#is', $h, $sel, PREG_SET_ORDER);
        foreach ($sel as $s) { preg_match_all('#<option[^>]*value="([^"]*)"[^>]*>(.*?)</option>#is', $s[2], $o, PREG_SET_ORDER); foreach ($o as $oo) $labels[$s[1] . '|' . html_entity_decode($oo[1])] = tekst($oo[2]); }
        // btw: toont de shop de prijs expliciet excl. btw, dan is display_price excl
        $excl = btw_vermeld(substr($h, 0, 600000), 'excl') && btw_vermeld($h, 'incl');
        if (is_array($vv)) foreach ($vv as $v) {
            if (isset($v['is_purchasable']) && !$v['is_purchasable']) continue;
            $p = (float)($v['display_price'] ?? 0); if ($p <= 0) continue;
            if ($excl) $p = round($p * (1 + btw_bron() / 100), 2);
            $namen = [];
            foreach (($v['attributes'] ?? []) as $a => $val) { $a2 = preg_replace('/^attribute_/', '', $a); if ($val === '') continue; $namen[] = $labels[$a2 . '|' . $val] ?? $val; }
            $vars[] = ['titel' => implode(' / ', $namen) ?: 'standaard', 'prijs' => $p, 'voorraad' => !empty($v['is_in_stock']) ? 1 : 0, 'ext' => (string)($v['variation_id'] ?? count($vars))];
        }
    } elseif ($platform === 'magento' && ($cfg = json_na($h, 'jsonConfig') ?: json_na($h, 'spConfig') ?: (preg_match("#initConfigurableOptions\w*\(\s*'\d+',\s*#", $h, $mm, PREG_OFFSET_CAPTURE) ? json_vanaf($h, $mm[0][1] + strlen($mm[0][0])) : null)) && !empty($cfg['attributes'])) {
        $per = [];
        foreach (($cfg['attributes'] ?? []) as $a) foreach (($a['options'] ?? []) as $o) foreach (($o['products'] ?? []) as $pid) $per[$pid][] = tekst($o['label']);
        foreach ($per as $pid => $lab) { $p = (float)($cfg['optionPrices'][$pid]['finalPrice']['amount'] ?? 0); if ($p <= 0) continue;
            $vars[] = ['titel' => implode(' / ', $lab), 'prijs' => $p, 'voorraad' => 1, 'ext' => (string)$pid]; }
    } elseif ($platform === 'prestashop' && preg_match('/var combinationsFromController = (\{.*?\});\s*\n/s', $h, $m)) {
        $comb = json_decode($m[1], true) ?: [];
        $basis = preg_match('/var productPriceTaxExcluded = ([\d.]+)/', $h, $b1) ? (float)$b1[1] : 0.0;
        $tax = preg_match('/var taxRate = ([\d.]+)/', $h, $t1) ? (float)$t1[1] : btw_bron();
        foreach ($comb as $cid => $c) {
            $p = $basis + (float)($c['price'] ?? 0); $sp = $c['specific_price'] ?? null;
            if (is_array($sp)) { if (!empty($sp['price']) && (float)$sp['price'] > 0) $p = (float)$sp['price'];
                $red = (float)($sp['reduction'] ?? 0); if ($red) $p = ($sp['reduction_type'] ?? '') === 'percentage' ? $p * (1 - $red) : $p - (($sp['reduction_tax'] ?? '1') === '0' ? $red : $red / (1 + $tax / 100)); }
            $incl = round($p * (1 + $tax / 100) + 1e-9, 2); if ($incl <= 0) continue;
            $vars[] = ['titel' => implode(' / ', array_map('tekst', (array)($c['attributes_values'] ?? []))), 'prijs' => $incl, 'voorraad' => ((int)($c['quantity'] ?? 1)) > 0 ? 1 : 0, 'ext' => (string)$cid];
        }
    }
    if (!$vars && preg_match('/page-product-configurable|product-type-configurable/i', $body)) return ['skip' => preg_match('/niet meer leverbaar|nicht mehr lieferbar|nicht mehr verfügbar|no longer available/i', $h) ? 'niet meer leverbaar' : 'geen koopbare variant'];
    // ProductGroup met losse varianten (bv. Magento grouped): elke variant is een eigen pagina met eigen prijs
    if (!$vars && $groep && !empty($groep['hasVariant'])) {
        foreach ($groep['hasVariant'] as $v) { $o = $v['offers'] ?? []; if (isset($o[0])) $o = $o[0];
            $p = prijsgetal($o['price'] ?? null); if (!$p) continue;
            $vars[] = ['titel' => tekst($v['name'] ?? ''), 'prijs' => $p, 'voorraad' => stripos((string)($o['availability'] ?? ''), 'OutOfStock') === false ? 1 : 0, 'ext' => (string)($v['sku'] ?? $v['url'] ?? count($vars)), 'ean' => gtin($v)];
            if (!empty($v['url'])) $kinderen[] = pad_van($v['url']); }
    }
    // Enkelvoudig product: prijs uit de pagina
    if (!$vars) {
        $o = $prod['offers'] ?? null; if (isset($o[0])) $o = $o[0]; $offer1 = is_array($o) ? $o : null;
        $p = prijsgetal($o['price'] ?? ($o['lowPrice'] ?? null));
        if (!$p && $platform === 'prestashop' && preg_match('/var productPrice = ([\d.]+)/', $h, $m)) $p = (float)$m[1];
        if (!$p && preg_match('/itemprop="price"[^>]*content="([\d.,]+)"/i', $h, $m)) $p = prijsgetal($m[1]); // ook itemProp (React-shops zoals ePages)
        if (!$p && preg_match('/property="(?:og|product):price:amount"\s+content="([\d.,]+)"/i', $h, $m)) $p = prijsgetal($m[1]);
        if (!$p && $platform === 'woocommerce' && preg_match('#<p class="price[^"]*">(.*?)</p>#is', $h, $m)) {
            $blok = preg_match('#<ins[^>]*>(.*?)</ins>#is', $m[1], $ins) ? $ins[1] : $m[1];
            if (preg_match('/([\d.]*\d+,\d{2})/', tekst($blok), $pm)) { $p = prijsgetal($pm[1]); if (btw_vermeld(tekst($m[1]), 'excl') && !btw_vermeld(tekst($m[1]), 'incl')) $p = round($p * (1 + btw_bron() / 100), 2); }
        }
        if (!$p && $platform === 'magento' && preg_match('/data-price-type="finalPrice"[^>]*data-price-amount="([\d.]+)"|data-price-amount="([\d.]+)"[^>]*data-price-type="finalPrice"/', $h, $m)) $p = (float)($m[1] ?: $m[2]);
        if ($p && $p > 0) $vars[] = ['titel' => 'standaard', 'prijs' => $p, 'voorraad' => stripos((string)($o['availability'] ?? ''), 'OutOfStock') === false ? 1 : 0, 'ext' => 'enkel', 'ean' => $prod ? gtin($prod) : ''];
    }
    // Eén prijs zonder maat in de naam: maat van de getoonde prijs uit het prijsblok, de Grundpreis of de gekozen maat.
    $keuze = maatkeuze($h); $inhoudBron = '';
    if (count($vars) === 1 && $vars[0]['titel'] === 'standaard' && inhoud($naam)[0] === null && ($mt = inhoud_bij_prijs($h, (float)$vars[0]['prijs'], $keuze, $offer1 ?? null))) {
        $vars[0]['titel'] = (!empty($keuze['extra']) ? $keuze['extra'] . ' / ' : '') . $mt['label']; $inhoudBron = $mt['bron']; }
    // Prijscontrole: welke regel is toegepast, en bevestigt een tweede bron op de pagina de prijs?
    $ref = [];
    foreach ($ld as $x) { $t = (array)($x['@type'] ?? []);
        if (array_intersect(['Offer', 'AggregateOffer'], $t)) foreach (['price', 'lowPrice', 'highPrice'] as $kk) if (($pv = prijsgetal($x[$kk] ?? null)) > 0) $ref[] = round($pv, 2); }
    $bevestigd = 0; foreach ($vars as $v) foreach ($ref as $rp) if (abs($rp - $v['prijs']) <= 0.02) { $bevestigd++; break; }
    $controle = ['staffel' => (bool)preg_match('/bestel meer|staffel|tiered-pricing|quantity discount|mengenrabatt|vanaf \d+ stuks|ab \d+ st(ü|u)ck|\b\d+\s*-\s*\d+\s*(stuks|st(ü|u)ck)/i', $h),
                 'excl' => !empty($excl), 'bevestigd' => $bevestigd, 'n' => count($vars), 'ref' => (bool)$ref];
    return ['naam' => $naam, 'merk' => (string)$merk, 'merkok' => (bool)$merkok, 'varianten' => $vars, 'kinderen' => $kinderen, 'controle' => $controle,
            'maatkeuze' => $keuze, 'inhoud_bron' => $inhoudBron, 'pagina_id' => pagina_id($h, is_scalar($prod['sku'] ?? null) ? (string)$prod['sku'] : '')];
}

// ---------- overige maten (Shopware-maatkeuze) ----------
// Shopware 5: de detail-URL met ?group[N]=optie toont die maat; die pagina lezen we gewoon (prijs + maat uit het prijsblok).
//   Kostenbewaking: shops met honderden kleurpagina's van één product (alleen de RAL-code verschilt) krijgen de maten één keer
//   per productfamilie + prijsklasse (maat en prijs van de getoonde variant). Een kleur met een andere prijs is een andere klasse en
//   wordt zelf uitgevouwen. Maatprijzen worden nooit naar andere kleurpagina's gekopieerd: elke opgeslagen prijs is echt op een pagina gezien.
// Shopware 6: elke variant heeft een eigen URL; die vragen we op via het switch-endpoint en lezen we als gewone productpagina.
function maat_plannen(array &$st, string $k, array $r, string $url): void {
    $kz = $r['maatkeuze'] ?? null; if (!$kz || count($kz['opties']) < 2 || count($r['varianten']) !== 1) return;
    if ($kz['soort'] === 'sw6') {
        if (empty($kz['switch']) || !zelfde_host((string)$kz['switch'], $st['host'])) return;
        $sl = 'sw6|' . preg_replace('#/switch.*$#', '', $kz['switch']) . '|' . json_encode($kz['anders']);
        if (isset($st['maat_gedaan'][$sl])) return; $st['maat_gedaan'][$sl] = $k;
        foreach ($kz['opties'] as $o) if (!$o['sel']) $st['maat_q'][] = ['sw6', $k, $kz['switch'], $kz['groep'], $o['w'], $kz['anders'], $o['uit']];
        return;
    }
    $v0 = $r['varianten'][0]; $eigen = inhoud((string)$v0['titel'])[1] ?? inhoud((string)$r['naam'])[1];
    $sl = 'sw5|' . familie($r['naam']) . '|' . ($eigen ?? '?') . '|' . round((float)$v0['prijs'], 2);
    if (isset($st['maat_gedaan'][$sl])) { $st['maat_zelfde'] = ($st['maat_zelfde'] ?? 0) + 1; return; }
    $st['maat_gedaan'][$sl] = $k;
    foreach ($kz['opties'] as $o) {
        if ($o['sel'] || ($eigen !== null && inhoud($o['label'])[1] == $eigen)) continue; // getoonde maat is al gemeten
        if ($o['uit']) continue; // uitverkocht/uitgeschakeld: Shopware toont dan de prijs van een andere variant (gemeten: 10 ltr voor 38,00 €)
        $st['maat_q'][] = ['sw5', $k, $url, $kz['groep'], $o['w'], $kz['anders'], $o['uit']];
    }
}
function zelfde_host(string $u, string $host): bool {
    $w = function ($h) { return preg_replace('/^www\./', '', host_ascii((string)$h)); };
    return $w(parse_url($u, PHP_URL_HOST)) === $w($host);
}
// Eén item uit de maat-wachtrij (één HTTP-verzoek).
function maat_stap(string $pl, array &$st): void {
    [$soort, $k, $url, $groep, $w, $anders, $uit] = array_shift($st['maat_q']);
    if (!isset($st['prod'][$k])) return;
    $max = (int)($GLOBALS['CFG']['maat_max'] ?? 400);
    if (($st['maat_n'] ?? 0) >= $max) { $st['log'][] = 'maatkeuze: limiet van ' . $max . ' verzoeken bereikt, ' . (count($st['maat_q']) + 1) . ' maten niet opgehaald'; $st['maat_q'] = []; return; }
    $st['maat_n'] = ($st['maat_n'] ?? 0) + 1;
    $opt = (array)$anders; $opt[$groep] = $w;
    if ($soort === 'sw6') {
        $b = haal_x($url . (strpos($url, '?') === false ? '?' : '&') . http_build_query(['switched' => $groep, 'options' => json_encode($opt)]), $code);
        $nu = (string)(($b ? json_decode($b, true) : null)['url'] ?? '');
        if (!preg_match('#^https?://#', $nu) || !zelfde_host($nu, $st['host'])) { $st['maat_mis'][] = $st['prod'][$k]['url'] . " (maat $w: switch HTTP $code)"; return; }
        $pk = pad_van($nu);
        if (!isset($st['kand'][$pk])) { $st['kand'][$pk] = ['url' => $nu, 'via' => $st['prod'][$k]['via']]; $st['lijst'][] = $pk; }
        return;
    }
    $u = preg_replace('/[?#].*$/', '', $url) . '?' . http_build_query($opt);
    $h = haal_x($u, $code);
    $r = $h ? lees_pagina($pl, $h, $u, ['merk' => 1], $st) : null;
    $gekozen = null;
    if ($r && !empty($r['maatkeuze']) && $r['maatkeuze']['groep'] === $groep) foreach ($r['maatkeuze']['opties'] as $o) if ($o['sel']) $gekozen = (string)$o['w'];
    // alleen als de pagina echt deze maat toont (anders valt Shopware stil terug op de standaardvariant)
    $lab = null; foreach ($r['maatkeuze']['opties'] ?? [] as $o) if ((string)$o['w'] === (string)$w) $lab = inhoud($o['label']);
    if (!$r || isset($r['skip']) || $gekozen !== (string)$w || count($r['varianten'] ?? []) !== 1 || !$lab || $lab[0] === null) {
        $st['maat_mis'][] = $st['prod'][$k]['url'] . " (maat $w: " . ($h ? 'niet getoond' : "HTTP $code") . ')'; return; }
    $v = $r['varianten'][0]; // maat = de gekozen optie (de pagina bevestigt dat die getoond wordt)
    // zelfde prijs als de getoonde maat bij een andere maat = de shop viel terug op de standaardprijs: niet opslaan
    $basis = $st['prod'][$k]['varianten'][0]; $bm = inhoud((string)$basis['titel'])[1] ?? inhoud((string)$st['prod'][$k]['naam'])[1];
    if (abs((float)$v['prijs'] - (float)$basis['prijs']) < 0.005 && $bm !== null && $bm != $lab[1]) {
        $st['maat_mis'][] = $st['prod'][$k]['url'] . " (maat $w: zelfde prijs als " . $basis['titel'] . ')'; return; }
    $st['prod'][$k]['varianten'][] = ['titel' => (!empty($r['maatkeuze']['extra']) ? $r['maatkeuze']['extra'] . ' / ' : '') . maat_label($lab[0], $lab[1] >= 10000), 'prijs' => $v['prijs'], 'voorraad' => $uit ? 0 : (int)$v['voorraad'], 'ext' => 'maat:' . $groep . '=' . $w,
        'ean' => (string)($v['ean'] ?? ''), 'veld' => $pl . ' pagina (maatkeuze)'];
    $st['prod'][$k]['controle']['n'] = count($st['prod'][$k]['varianten']);
}

// BigCommerce: varianten + prijzen incl. btw rechtstreeks uit de storefront-catalogus
function bc_varianten(string $home, string $token, array $ids): array {
    $q = json_encode(['query' => 'query($i:[Int!]){site{products(entityIds:$i,first:50){edges{node{entityId name path prices(includeTax:true){price{value}} variants(first:100){edges{node{entityId isPurchasable inventory{isInStock} options{edges{node{displayName values{edges{node{label}}}}}} prices(includeTax:true){price{value}}}}}}}}}}', 'variables' => ['i' => array_values($ids)]]);
    $b = haal_x($home . 'graphql', $c, $e, $q, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Origin: ' . rtrim($home, '/')]);
    $d = $b ? json_decode($b, true) : null; $uit = [];
    foreach (($d['data']['site']['products']['edges'] ?? []) as $e2) { $p = $e2['node']; $vs = [];
        foreach ($p['variants']['edges'] as $v) { $v = $v['node']; if (empty($v['isPurchasable'])) continue; $pr = (float)($v['prices']['price']['value'] ?? 0); if ($pr <= 0) continue;
            $lab = []; foreach ($v['options']['edges'] as $o) $lab[] = tekst($o['node']['values']['edges'][0]['node']['label'] ?? '');
            $vs[] = ['titel' => implode(' / ', array_filter($lab)) ?: 'standaard', 'prijs' => $pr, 'voorraad' => !empty($v['inventory']['isInStock']) ? 1 : 0, 'ext' => (string)$v['entityId']]; }
        if (!$vs && ($pr = (float)($p['prices']['price']['value'] ?? 0)) > 0) $vs[] = ['titel' => 'standaard', 'prijs' => $pr, 'voorraad' => 1, 'ext' => 'enkel'];
        $uit[(int)$p['entityId']] = ['naam' => $p['name'], 'varianten' => $vs]; }
    return $uit;
}

// ---------- de stapmachine ----------
function gen_start(array $bron, string $t): array {
    return ['host' => $bron['domein'], 'fase' => 'g_sitemap', 'sm_q' => [], 'sm_gezien' => [], 'sm_n' => 0, 'kand' => [], 'n_page' => 1, 'n_n' => 0, 'n_opgave' => null,
            'lijst' => [], 'i' => 0, 'prod' => [], 'overgeslagen' => [], 'np' => 0, 'nv' => 0, 'fout' => 0, 'log' => [], 't' => $t, 'sm_init' => false];
}
function gen_stap(array $bron, int $run, array &$st, float $tot): void {
    $home = 'https://' . $bron['domein'] . '/'; $pl = $bron['platform'];
    while (microtime(true) < $tot) {
        if ($st['fase'] === 'g_sitemap') {
            if (!$st['sm_init']) { $st['sm_q'] = sitemap_start($home); $st['sm_init'] = true; if (!$st['sm_q']) $st['log'][] = 'geen sitemap gevonden'; }
            if ($st['sm_q']) sitemap_stap($st); else { $st['fase'] = 'g_native'; $st['sm_kand'] = count($st['kand']); }
        } elseif ($st['fase'] === 'g_native') {
            if (native_stap($bron, $st)) { $st['fase'] = 'g_lijst'; }
        } elseif ($st['fase'] === 'g_lijst') {
            $st['lijst'] = array_keys(array_diff_key($st['kand'], $st['prod'])); $st['i'] = 0; $st['fase'] = 'g_prod'; // al uit de catalogus gelezen = overslaan
            // BigCommerce: alle merkproducten in één keer via de catalogus (prijs incl. btw)
            if ($pl === 'bigcommerce' && !empty($st['bc_token'])) {
                $ids = []; foreach ($st['kand'] as $k => $c) if (!empty($c['bc_id'])) $ids[$k] = $c['bc_id'];
                foreach (array_chunk($ids, 40, true) as $deel) { $r = bc_varianten($home, $st['bc_token'], $deel);
                    foreach ($deel as $k => $id) if (isset($r[$id])) $st['kand'][$k]['bc'] = $r[$id]; }
            }
        } elseif ($st['fase'] === 'g_prod') {
            if ($st['i'] >= count($st['lijst'])) { $st['fase'] = !empty($st['maat_q']) ? 'g_maat' : 'g_opslaan'; continue; }
            $k = $st['lijst'][$st['i']++]; $c = $st['kand'][$k];
            $h = haal_x($c['url'], $code, $eind);
            if (!$h) {
                if ($code === 0 && empty($st['opnieuw'][$k])) { $st['opnieuw'][$k] = 1; $st['lijst'][] = $k; continue; } // traag: later nog één keer
                $st['overgeslagen'][] = $c['url'] . ($code === 0 ? ' (reageert niet)' : " (HTTP $code)"); if ($code === 0) $st['niet_bereikt'][] = $c['url']; continue; }
            if ($eind && pad_van($eind) !== $k && pad_van($eind) !== pad_van(preg_replace('/\?.*/', '', $c['url']))) { $st['overgeslagen'][] = $c['url'] . ' (doorgestuurd naar ' . pad_van($eind) . ')'; continue; }
            $r = lees_pagina($pl, $h, $c['url'], $c, $st);
            if ($pl === 'bigcommerce' && !empty($c['bc'])) { $r['varianten'] = $c['bc']['varianten']; $r['naam'] = $r['naam'] ?: $c['bc']['naam']; $r['merkok'] = true; }
            if (isset($r['skip'])) { if (strpos($c['via'], 'N') !== false || $r['skip'] !== 'overzichtspagina') $st['overgeslagen'][] = $c['url'] . ' (' . $r['skip'] . ')'; continue; }
            if (!$r || !$r['merkok']) { if (strpos($c['via'], 'N') !== false) $st['overgeslagen'][] = $c['url'] . ' (geen ' . merk() . ')'; continue; }
            if (!$r['varianten']) { $st['overgeslagen'][] = $c['url'] . ' (geen koopbare variant)'; continue; }
            // dezelfde variant onder twee URL's (Shopware 6: /x_SW10025 toont dezelfde variant als /x_SW10025.3): één keer tellen
            if (!empty($r['pagina_id']) && count($r['varianten']) === 1) { $dk = $r['pagina_id'] . '|' . (inhoud((string)$r['varianten'][0]['titel'])[1] ?? inhoud($r['naam'])[1]) . '|' . $r['varianten'][0]['prijs'];
                if (isset($st['pid_gezien'][$dk])) { $st['overgeslagen'][] = $c['url'] . ' (zelfde variant als ' . $st['prod'][$st['pid_gezien'][$dk]]['url'] . ')'; continue; }
                $st['pid_gezien'][$dk] = $k; }
            $st['prod'][$k] = ['url' => $c['url'], 'via' => $c['via'], 'naam' => $r['naam'], 'merk' => $r['merk'], 'varianten' => $r['varianten'], 'kinderen' => $r['kinderen'], 'controle' => $r['controle'] ?? []];
            if ($pl === 'onbekend') maat_plannen($st, $k, $r, $c['url']); // Shopware wordt als 'onbekend' herkend; PrestaShop gebruikt ook group[N], maar heeft een eigen route
        } elseif ($st['fase'] === 'g_maat') {
            // overige maten ophalen; nieuwe Shopware 6-variant-URL's gaan daarna terug door g_prod
            if (empty($st['maat_q'])) { $st['fase'] = $st['i'] < count($st['lijst']) ? 'g_prod' : 'g_opslaan'; continue; }
            maat_stap($pl, $st);
        } elseif ($st['fase'] === 'g_opslaan') {
            // losse variantpagina's die al onder een productgroep vallen, niet dubbel tellen
            $kind = []; foreach ($st['prod'] as $p) foreach ($p['kinderen'] as $x) $kind[basename($x)] = 1;
            foreach ($st['prod'] as $k => $p) { if (empty($p['kinderen']) && isset($kind[basename($k)])) { unset($st['prod'][$k]); continue; }
                $pid = upsert_product((int)$bron['id'], $k, $p['naam'], $p['url'], $p['merk'] ?: merk(), $st['t']); $st['np']++;
                foreach ($p['varianten'] as $v) {
                    $titel = $v['titel'] === 'standaard' ? $p['naam'] : $v['titel'];
                    [$l, $std] = inhoud($titel); if ($l === null) [$l, $std] = inhoud($p['naam']);
                    $vid = upsert_variant($pid, (string)$v['ext'], $v['titel'], (string)($v['ean'] ?? ''), '', $l, $std, kleurtype($titel) ?: kleurtype_naam($p["naam"]), $st['t']); // zoals kleur_nu(): variantnaam, anders productnaam
                    meet($vid, $run, (float)$v['prijs'], null, (int)$v['voorraad'], '', (string)($v['veld'] ?? $pl . ' pagina'), $st['t']); $st['nv']++; } }
            // producten die bij deze bron niet meer gevonden zijn, uit de vergelijking
            db()->prepare('UPDATE product SET zichtbaar=CASE WHEN laatst_gezien=? THEN 1 ELSE 0 END WHERE bron_id=?')->execute([$st['t'], (int)$bron['id']]);
            $viaS = count(array_filter($st['prod'], function ($p) { return strpos($p['via'], 'S') !== false; }));
            $viaN = count(array_filter($st['prod'], function ($p) { return strpos($p['via'], 'N') !== false; }));
            $alleenS = array_values(array_map(function ($p) { return $p['naam']; }, array_filter($st['prod'], function ($p) { return $p['via'] === 'S'; })));
            $alleenN = array_values(array_map(function ($p) { return $p['naam']; }, array_filter($st['prod'], function ($p) { return strpos($p['via'], 'S') === false; })));
            $pc = ['staffel' => 0, 'excl' => 0, 'bevestigd' => 0, 'varianten' => 0, 'met_ref' => 0];
            foreach ($st['prod'] as $p) { $c2 = $p['controle'] ?? []; $pc['staffel'] += !empty($c2['staffel']); $pc['excl'] += !empty($c2['excl']);
                $pc['bevestigd'] += (int)($c2['bevestigd'] ?? 0); $pc['varianten'] += (int)($c2['n'] ?? 0); $pc['met_ref'] += !empty($c2['ref']) ? (int)$c2['n'] : 0; }
            $pc['markt'] = prijs_marktcontrole((int)$bron['id']);
            $dekking = ['route' => 'dubbel', 'prijscontrole' => $pc, 'sitemap_urls' => $st['sm_n'], 'catalogus' => $st['n_n'], 'catalogus_opgave' => $st['n_opgave'], 'kandidaten' => count($st['kand']),
                'gevonden' => $st['np'], 'niet_bereikt' => $st['niet_bereikt'] ?? [], 'via_sitemap' => $viaS, 'via_catalogus' => $viaN, 'alleen_sitemap' => $alleenS, 'alleen_catalogus' => $alleenN, 'overgeslagen' => $st['overgeslagen'],
                'op_merk' => $st['np'], 'op_titel' => [], 'maten' => ['verzoeken' => $st['maat_n'] ?? 0, 'zelfde_familie' => $st['maat_zelfde'] ?? 0, 'mislukt' => $st['maat_mis'] ?? []]];
            db()->prepare('UPDATE run SET dekking=?, methode=? WHERE id=?')->execute([json_encode($dekking, JSON_UNESCAPED_UNICODE), 'sitemap + ' . $pl . '-catalogus, elke productpagina gelezen', $run]);
            $st['log'][] = $st['sm_n'] . ' sitemap-URLs · ' . count($st['kand']) . ' kandidaten · ' . $st['np'] . ' ' . merk() . '-producten';
            $st['fase'] = 'klaar';
        } else break;
    }
}
// Liggen de prijzen van deze bron structureel ~17% (excl. btw) of ~7-10% (staffel) onder de onze? Dan klopt de prijsregel waarschijnlijk niet.
function prijs_marktcontrole(int $bronId): array {
    $w = db()->query("SELECT v.inhoud_std, v.titel vt, p.titel pt, m.prijs, b.is_eigen FROM variant v JOIN product p ON p.id=v.product_id JOIN bron b ON b.id=p.bron_id
        JOIN meting m ON m.id=(SELECT id FROM meting WHERE variant_id=v.id ORDER BY tijd DESC, id DESC LIMIT 1) WHERE (b.is_eigen=1 OR b.id=" . (int)$bronId . ") AND v.inhoud_std IS NOT NULL AND COALESCE(p.zichtbaar,1)=1")->fetchAll();
    $sleutel = 'naamsleutel';
    $ons = []; foreach ($w as $r) if ((int)$r['is_eigen'] === 1) $ons[$sleutel($r['pt']) . '|' . $r['inhoud_std']][] = (float)$r['prijs'];
    $ratio = [];
    foreach ($w as $r) if ((int)$r['is_eigen'] !== 1) { $k = $sleutel($r['pt']) . '|' . $r['inhoud_std']; if (isset($ons[$k]) && $r['prijs'] > 0) $ratio[] = $r['prijs'] / min($ons[$k]); }
    if (count($ratio) < 4) return ['n' => count($ratio), 'mediaan' => null, 'oordeel' => 'te weinig vergelijkbaar'];
    sort($ratio); $med = $ratio[intdiv(count($ratio), 2)];
    // Geen conclusie over de oorzaak (btw, actie of gewoon een discounter): alleen signaleren dat het opvalt.
    $oordeel = $med < drempel('prijscheck') ? 'erg laag' : 'normaal';
    return ['n' => count($ratio), 'mediaan' => round($med, 3), 'oordeel' => $oordeel];
}
function gen_voortgang(array $st): array {
    if ($st['fase'] === 'g_sitemap') return ['pct' => 10, 'tekst' => 'Sitemap lezen: ' . number_format($st['sm_n'], 0, ',', '.') . ' pagina\'s'];
    if ($st['fase'] === 'g_native') return ['pct' => 30, 'tekst' => 'Catalogus lezen: ' . number_format($st['n_n'], 0, ',', '.') . ' producten'];
    if ($st['fase'] === 'g_maat') return ['pct' => 97, 'tekst' => 'Maten ophalen: nog ' . count($st['maat_q']) . ' (' . (int)($st['maat_n'] ?? 0) . ' gedaan)'];
    if ($st['fase'] === 'g_prod') return ['pct' => 40 + (int)round(58 * $st['i'] / max(1, count($st['lijst']))), 'tekst' => 'Productpagina\'s: ' . $st['i'] . ' van ' . count($st['lijst'])];
    return ['pct' => 99, 'tekst' => 'Opslaan'];
}

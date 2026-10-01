<?php
// Omzet per product over de laatste 12 maanden, uit de Lightspeed API (alleen onze eigen shop).
// Eerste vulling: één API-verzoek per order (rate limit ~1 per seconde), daarna alleen nieuwe orders: seconden.
// Tot de 12 maanden compleet zijn, geldt als indicatie: stockSold (totaal ooit verkocht) x huidige prijs.

function omzet_db(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS verkoop_order (id INTEGER PRIMARY KEY, datum TEXT, status TEXT, gelezen INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS verkoop_regel (order_id INTEGER, product_id TEXT, variant_id TEXT, aantal REAL, omzet REAL);
        CREATE INDEX IF NOT EXISTS vr_order ON verkoop_regel(order_id);
        CREATE TABLE IF NOT EXISTS omzet_run (id INTEGER PRIMARY KEY, start TEXT, eind TEXT, state TEXT, voortgang TEXT, slot INTEGER);
        CREATE TABLE IF NOT EXISTS verkocht_indicatie (product_id TEXT PRIMARY KEY, omzet REAL, gemeten TEXT);');
}
function api_traag(string $pad, array $q = []) {
    global $CFG;
    for ($poging = 0; $poging < 3; $poging++) {
        $url = api_basis() . $pad . ($q ? '?' . http_build_query($q) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => stap_timeout(20), CURLOPT_USERPWD => $CFG['api']['key'] . ':' . $CFG['api']['secret'], CURLOPT_HEADER => true]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        usleep(1050000); // rate limit: 300 per 5 minuten
        if ($code === 429) { if (stap_rest() < 12) throw new RuntimeException('limiet', 429); sleep(8); continue; }
        if ($code !== 200) throw new RuntimeException("API $pad gaf HTTP $code");
        return json_decode(substr((string)$r, $hs), true);
    }
    throw new RuntimeException("API $pad: te vaak rate limit");
}
// snelle indicatie: stockSold per variant x prijs, opgeteld per product (1-2 verzoeken)
function omzet_indicatie(): int {
    omzet_db(); $per = [];
    $page = 1;
    do { $d = api_traag('/variants.json', ['limit' => 250, 'page' => $page, 'fields' => 'id,product,priceIncl,stockSold']); $rij = $d['variants'] ?? [];
        foreach ($rij as $v) { $pid = (string)($v['product']['resource']['id'] ?? ''); if ($pid === '') continue; $per[$pid] = ($per[$pid] ?? 0) + (float)($v['stockSold'] ?? 0) * (float)($v['priceIncl'] ?? 0); }
        $page++; } while (count($rij) === 250);
    db()->exec('DELETE FROM verkocht_indicatie');
    $q = db()->prepare('INSERT INTO verkocht_indicatie VALUES(?,?,?)'); foreach ($per as $pid => $o) $q->execute([$pid, $o, nu()]);
    return count($per);
}
// Periode voor best verkocht: de laatste 12 maanden, of korter als de shop pas sinds 'omzet_vanaf' (config.php, JJJJ-MM-DD) zo draait.
function omzet_vanaf(): string {
    $v = date('Y-m-d', strtotime('-365 days')); $c = (string)($GLOBALS['CFG']['omzet_vanaf'] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $c) && $c > $v ? $c : $v;
}
function omzet_periode(): string { return omzet_vanaf() > date('Y-m-d', strtotime('-365 days')) ? 'sinds ' . date('d-m-Y', strtotime(omzet_vanaf())) : 'laatste 12 maanden'; }
function omzet_start(): int {
    omzet_db();
    $open = db()->query('SELECT id FROM omzet_run WHERE eind IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($open) return (int)$open;
    $vanaf = omzet_vanaf();
    $laatst = db()->query('SELECT MAX(datum) FROM verkoop_order')->fetchColumn();
    $st = ['fase' => 'o_lijst', 'page' => 1, 'vanaf' => $laatst && $laatst > $vanaf ? substr($laatst, 0, 10) : $vanaf, 'nieuw' => 0, 'gelezen' => 0];
    db()->prepare('INSERT INTO omzet_run(start,state) VALUES(?,?)')->execute([nu(), json_encode($st)]);
    return (int)db()->lastInsertId();
}
function omzet_stap(int $run, float $budget = 20.0): array {
    omzet_db();
    $q = db()->prepare('SELECT * FROM omzet_run WHERE id=?'); $q->execute([$run]); $r = $q->fetch();
    if (!$r || $r['eind']) return ['klaar' => true, 'pct' => 100, 'tekst' => 'klaar'];
    $l = db()->prepare('UPDATE omzet_run SET slot=? WHERE id=? AND (slot IS NULL OR slot < ?)'); $l->execute([time(), $run, time() - 60]);
    if (!$l->rowCount()) return ['klaar' => false] + (json_decode((string)$r['voortgang'], true) ?: ['pct' => 0, 'tekst' => 'Bezig…']);
    $st = json_decode((string)$r['state'], true); $tot = microtime(true) + $budget; $GLOBALS['STAP_EIND'] = $tot + 22;
    try {
        while (microtime(true) < $tot) {
            if ($st['fase'] === 'o_lijst') {
                $d = api_traag('/orders.json', ['limit' => 250, 'page' => $st['page'], 'created_at_min' => $st['vanaf'], 'fields' => 'id,createdAt,status']);
                $rij = $d['orders'] ?? [];
                $ins = db()->prepare('INSERT OR IGNORE INTO verkoop_order(id,datum,status,gelezen) VALUES(?,?,?,0)');
                foreach ($rij as $o) { $ins->execute([(int)$o['id'], substr((string)$o['createdAt'], 0, 19), (string)$o['status']]); $st['nieuw'] += $ins->rowCount(); }
                $st['page']++;
                if (count($rij) < 250) $st['fase'] = 'o_regels';
            } elseif ($st['fase'] === 'o_regels') {
                $id = db()->query("SELECT id FROM verkoop_order WHERE gelezen=0 AND status NOT LIKE 'cancel%' ORDER BY datum DESC LIMIT 1")->fetchColumn();
                if (!$id) { $st['fase'] = 'klaar'; break; }
                $d = api_traag("/orders/$id/products.json", ['fields' => 'product,variant,quantityOrdered,quantityRefunded,priceIncl']);
                db()->prepare('DELETE FROM verkoop_regel WHERE order_id=?')->execute([$id]);
                $ins = db()->prepare('INSERT INTO verkoop_regel VALUES(?,?,?,?,?)');
                foreach (($d['orderProducts'] ?? []) as $p) { $n = (float)($p['quantityOrdered'] ?? 0); $terug = (float)($p['quantityRefunded'] ?? 0);
                    $ins->execute([$id, (string)($p['product']['resource']['id'] ?? ''), (string)($p['variant']['resource']['id'] ?? ''), $n - $terug, $n > 0 ? (float)($p['priceIncl'] ?? 0) * ($n - $terug) / $n : 0]); }
                db()->prepare('UPDATE verkoop_order SET gelezen=1 WHERE id=?')->execute([$id]); $st['gelezen']++;
            } else break;
        }
    } catch (Throwable $ex) { if ($ex->getCode() !== 429) $st['fout'] = $ex->getMessage(); } // 429 = API-limiet: volgende stap gaat gewoon door
    $open = (int)db()->query("SELECT COUNT(*) FROM verkoop_order WHERE gelezen=0 AND status NOT LIKE 'cancel%'")->fetchColumn();
    $alle = (int)db()->query("SELECT COUNT(*) FROM verkoop_order WHERE status NOT LIKE 'cancel%'")->fetchColumn();
    $v = $st['fase'] === 'o_lijst' ? ['pct' => 5, 'tekst' => 'Orders ophalen'] : ['pct' => $alle ? (int)round(100 * ($alle - $open) / $alle) : 100, 'tekst' => 'Orderregels: ' . ($alle - $open) . ' van ' . $alle];
    if ($st['fase'] === 'klaar' || !empty($st['fout'])) {
        db()->prepare('UPDATE omzet_run SET eind=?, state=NULL, slot=NULL, voortgang=? WHERE id=?')->execute([nu(), json_encode(['pct' => 100, 'tekst' => !empty($st['fout']) ? 'Gestopt: ' . $st['fout'] : 'Klaar']), $run]);
        return ['klaar' => true, 'pct' => 100, 'tekst' => !empty($st['fout']) ? 'Gestopt: ' . $st['fout'] : 'Klaar'];
    }
    db()->prepare('UPDATE omzet_run SET state=?, voortgang=?, slot=NULL WHERE id=?')->execute([json_encode($st), json_encode($v), $run]);
    return ['klaar' => false] + $v;
}
// Omzet incl. btw per Lightspeed-product-id over de laatste 12 maanden (of de indicatie als die nog niet compleet is)
function omzet_per_product(): array {
    omzet_db();
    $alle = (int)db()->query("SELECT COUNT(*) FROM verkoop_order WHERE status NOT LIKE 'cancel%' AND datum >= " . db()->quote(omzet_vanaf()) . "")->fetchColumn();
    $open = (int)db()->query("SELECT COUNT(*) FROM verkoop_order WHERE gelezen=0 AND status NOT LIKE 'cancel%' AND datum >= " . db()->quote(omzet_vanaf()) . "")->fetchColumn();
    if ($alle > 0 && $open === 0) {
        $r = db()->query("SELECT r.product_id, SUM(r.omzet) o FROM verkoop_regel r JOIN verkoop_order v ON v.id=r.order_id WHERE v.datum >= " . db()->quote(omzet_vanaf()) . " AND v.status NOT LIKE 'cancel%' GROUP BY r.product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['bron' => '12 maanden', 'omzet' => $r, 'orders' => $alle];
    }
    // indicatie (totaal ooit verkocht) alleen als de shop al langer zo draait; met 'omzet_vanaf' telt oude verkoop niet mee
    $r = empty($GLOBALS['CFG']['omzet_vanaf']) ? db()->query('SELECT product_id, omzet FROM verkocht_indicatie')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    return ['bron' => $r ? 'indicatie' : 'geen', 'omzet' => $r, 'orders' => $alle, 'open' => $open];
}

// Omzet incl. btw per Lightspeed-variant-id over de laatste 12 maanden (leeg zolang best verkocht nog niet is opgehaald)
function omzet_per_variant(): array {
    static $o = null;
    if ($o === null) { omzet_db(); $o = db()->query("SELECT r.variant_id, SUM(r.omzet) o FROM verkoop_regel r JOIN verkoop_order v ON v.id=r.order_id WHERE v.datum >= " . db()->quote(omzet_vanaf()) . " AND v.status NOT LIKE 'cancel%' GROUP BY r.variant_id")->fetchAll(PDO::FETCH_KEY_PAIR); }
    return $o;
}
// ---------- Marge per variant: verkoopprijs vs inkoopprijs zoals ingevuld in Lightspeed (priceCost) ----------
function marge_db(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS marge (variant_ext TEXT PRIMARY KEY, product_ext TEXT, inkoop REAL, excl REAL, incl REAL, gemeten TEXT)');
}
// Haalt alle varianten op (250 per verzoek, ~1 s per verzoek). Geeft [aantal, met inkoopprijs, compleet].
function marge_ophalen(): array {
    marge_db(); $n = 0; $met = 0; $page = 1; $tot = microtime(true) + 40; $t = nu();
    $q = db()->prepare('INSERT OR REPLACE INTO marge VALUES(?,?,?,?,?,?)');
    do { $d = api_traag('/variants.json', ['limit' => 250, 'page' => $page, 'fields' => 'id,product,priceExcl,priceIncl,priceCost']); $rij = $d['variants'] ?? [];
        foreach ($rij as $v) { $c = (float)($v['priceCost'] ?? 0); $n++; if ($c > 0) $met++;
            $q->execute([(string)$v['id'], (string)($v['product']['resource']['id'] ?? ''), $c > 0 ? $c : null, (float)($v['priceExcl'] ?? 0), (float)($v['priceIncl'] ?? 0), $t]); }
        $page++; } while (count($rij) === 250 && microtime(true) < $tot);
    return [$n, $met, count($rij) < 250];
}
// variant-id (Lightspeed) => ['inkoop','excl','pct','eur']
function marge_per_variant(): array {
    marge_db(); $uit = [];
    foreach (db()->query('SELECT * FROM marge') as $r) { $ex = (float)$r['excl']; $ink = $r['inkoop'] === null ? null : (float)$r['inkoop'];
        $uit[(string)$r['variant_ext']] = ['inkoop' => $ink, 'excl' => $ex, 'pct' => ($ink !== null && $ex > 0) ? ($ex - $ink) / $ex * 100 : null, 'eur' => $ink !== null ? $ex - $ink : null, 'gemeten' => $r['gemeten']]; }
    return $uit;
}

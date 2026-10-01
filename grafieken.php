<?php
// Marktgrafieken: prijsindex, positie, stunters, aanbiederprofiel, heatmap, marktkaart.
// Methode (standaard in price intelligence): alleen gematchte paren tellen, mediaan i.p.v. gemiddelde (ongevoelig voor uitschieters),
// optioneel gewogen naar onze eigen omzet zodat de producten waar het geld zit zwaarder wegen.

function g_mediaan(array $x) { if (!$x) return null; sort($x); $n = count($x); return $n % 2 ? $x[intdiv($n, 2)] : ($x[$n / 2 - 1] + $x[$n / 2]) / 2; }
function g_pct($v, int $dec = 1): string { if ($v === null) return '—'; return ($v > 0.05 ? '+' : ($v < -0.05 ? '−' : '')) . number_format(abs($v), $dec, ',', '') . '%'; }
// kleur vanuit ons perspectief: rood = wij duurder, groen = wij goedkoper, grijs = gelijk (±0,5%)
// Onze minimale marge (%), zelf in te stellen in Onze shop → Dekking. null = nog niet ingesteld (dan kleurt niets rood op marge).
function g_min() { $v = instelling('min_marge'); return ($v === null || $v === false || $v === '') ? null : (float)$v; }
function g_minv(): float { $m = g_min(); return $m === null ? -INF : $m; }
function g_kleur($v): string { return abs($v) < 0.5 ? '#b9bcc2' : ($v > 0 ? '#d64545' : '#2e9e4f'); }

function markt_stats(array $met, array $conc, array $omzet): array {
    $A = []; foreach ($conc as $cb) $A[(int)$cb['id']] = ['id' => (int)$cb['id'], 'naam' => $cb['naam'], 'ons' => [], 'markt' => [], 'goedkoopst' => 0, 'stunts' => [], 'n' => 0];
    $pos = ['goedkoopst' => 0, 'onder' => 0, 'op' => 0, 'boven' => 0, 'duurst' => 0];
    $wv = []; $ww = []; $vsmed = [];
    // omzet per product verdelen over zijn vergeleken varianten
    $nPerP = []; foreach ($met as $m) $nPerP[$m['pid']] = ($nPerP[$m['pid']] ?? 0) + 1;
    foreach ($met as $m) {
        $wij = (float)$m['ons']['prijs']; $med = (float)$m['med']; $vsmed[] = $m['vsmed'];
        $o = (float)($omzet[(string)($m['ons']['ext_id'] ?? '')] ?? 0); if ($o > 0) { $wv[] = $m['vsmed'] * $o / $nPerP[$m['pid']]; $ww[] = $o / $nPerP[$m['pid']]; }
        $alle = array_merge([$wij], $m['p']); $min = min($alle); $max = max($alle);
        $gelijkTop = count(array_filter($m['p'], function ($x) use ($wij) { return abs($x - $wij) <= 0.005; }));
        if ($wij <= $min + 0.005) $pos['goedkoopst']++;                        // laagste, ook gedeeld
        elseif (abs($m['vsmed']) < 0.5) $pos['op']++;
        elseif ($m['vsmed'] < 0) $pos['onder']++;
        elseif ($wij >= $max - 0.005 && !$gelijkTop) $pos['duurst']++;       // alleen als wij alléén de duurste zijn
        else $pos['boven']++;
        foreach ($m['perBron'] as $bid => $c) { if (!isset($A[$bid])) continue; $p = (float)$c['prijs'];
            $A[$bid]['n']++; $A[$bid]['ons'][] = ($wij - $p) / $p * 100; $A[$bid]['markt'][] = ($p - $med) / $med * 100;
            if ($p <= $min + 0.005 && $p < $wij - 0.005) $A[$bid]['goedkoopst']++;
            if ($m['n'] >= 2 && $p < $med * (1 - drempel('stunter_pct') / 100)) $A[$bid]['stunts'][] = ['m' => $m, 'prijs' => $p, 'onder' => ($p - $med) / $med * 100]; }
    }
    $tot = count($met);
    foreach ($A as &$a) { $a['wij_vs'] = g_mediaan($a['ons']); $a['vs_markt'] = g_mediaan($a['markt']); $a['dekking'] = $tot ? $a['n'] / $tot * 100 : 0;
        $a['profiel'] = $a['vs_markt'] === null ? '' : ($a['vs_markt'] < -drempel('profiel_pct') ? 'discounter' : ($a['vs_markt'] > drempel('profiel_pct') ? 'premium' : 'marktvolger')); }
    unset($a);
    $A = array_filter($A, function ($a) { return $a['n'] > 0; });
    return ['aanb' => $A, 'pos' => $pos, 'tot' => $tot, 'index' => g_mediaan($vsmed), 'gewogen' => $ww ? array_sum($wv) / array_sum($ww) : null];
}

// horizontale staven rond een nullijn: items [naam, v, sub, link]
function g_staven(array $items, callable $fmt, int $naamBreed = 220): string {
    if (!$items) return '<p class="muted" style="margin:0">Nog niets te vergelijken.</p>';
    $max = max(array_map(function ($i) { return abs($i['v']); }, $items)) ?: 1; $h = '';
    foreach ($items as $i) { $w = round(abs($i['v']) / $max * 50, 1); $kl = g_kleur($i['v']);
        $h .= '<a class="gr-rij" style="grid-template-columns:minmax(0,' . $naamBreed . 'px) 1fr 70px" href="' . ($i['link'] ?? '#') . '"><span class="vnaam">' . $i['naam'] . (isset($i['sub']) ? ' <span class="muted" style="font-size:12px">' . e($i['sub']) . '</span>' : '') . '</span>'
            . '<span class="gr-bak"><span class="gr-nul"></span><span class="gr-staaf" style="background:' . $kl . ';width:' . max($w, 0.8) . '%;' . ($i['v'] >= 0 ? 'left:50%' : 'right:50%') . '"></span></span>'
            . '<span class="num" style="text-align:right;color:' . ($kl === '#b9bcc2' ? 'var(--muted)' : $kl) . '">' . $fmt($i['v']) . '</span></a>'; }
    return $h;
}

// KPI-strook + positieverdeling
function g_kpis(array $S, string $omzetBron): string {
    $vaakst = null; foreach ($S['aanb'] as $a) if ($a['goedkoopst'] && ($vaakst === null || $a['goedkoopst'] > $vaakst['goedkoopst'])) $vaakst = $a;
    $idx = $S['index']; $gw = $S['gewogen'];
    $h = '<div class="kpis">';
    $h .= '<div class="kpi"><small>' . wn() . ' vs markt, per variant</small><div style="color:' . ($idx === null ? 'var(--ink)' : g_kleur($idx)) . '">' . g_pct($idx) . '</div><p>de middelste van de ' . $S['tot'] . ' varianten van ' . wn() . ': zo ver zit ' . wn() . ' van de mediaan, elke variant telt even zwaar</p></div>';
    $gwTxt = $gw === null ? 'vul eerst de best verkochte producten (' . wn() . ')' : (abs($gw) < 0.5 ? 'waar de omzet zit, zit ' . wn() . ' gemiddeld op de mediaan' : 'waar de omzet zit, is ' . wn() . ' gemiddeld ' . number_format(abs($gw), 1, ',', '') . '% ' . ($gw > 0 ? 'duurder' : 'goedkoper') . ' dan de mediaan (elk product telt mee naar zijn omzet)') . ($omzetBron === 'indicatie' ? ' · indicatie' : '');
    $h .= '<div class="kpi"><small>' . wn() . ' vs markt, naar omzet</small><div style="color:' . ($gw === null ? 'var(--muted)' : g_kleur($gw)) . '">' . ($gw === null ? '—' : g_pct($gw)) . '</div><p>' . $gwTxt . '</p></div>';
    $h .= '<div class="kpi"><small>' . wn() . ' goedkoopst</small><div>' . $S['pos']['goedkoopst'] . '</div><p>van ' . $S['tot'] . ' varianten, ook gedeeld</p></div>';
    $h .= '<div class="kpi"><small>Vaakst goedkoopst</small><div style="font-size:22px">' . ($vaakst ? e($vaakst['naam']) : '—') . '</div><p>' . ($vaakst ? $vaakst['goedkoopst'] . ' varianten onder ' . wn() . ' en de rest' : 'niemand is goedkoper dan ' . wn()) . '</p></div>';
    $h .= '</div>';
    $p = $S['pos']; $t = max(1, array_sum($p));
    $del = ['goedkoopst' => ['Goedkoopst', '#1d6a3d'], 'onder' => ['Onder mediaan', '#5fb27a'], 'op' => ['Op mediaan', '#b9bcc2'], 'boven' => ['Boven mediaan', '#e58a8a'], 'duurst' => ['Duurst', '#b42318']];
    $h .= '<div class="card" style="margin-bottom:16px"><div class="gr-kop"><h2>Positie ' . wn() . ' in de markt</h2></div><p class="gr-uitleg">Per variant: waar staat de prijs van ' . wn() . ' tussen alle aanbieders</p><div class="gr-stapel" style="height:30px">';
    foreach ($del as $k => [$l, $kl]) if ($p[$k]) $h .= '<span style="flex:' . $p[$k] . ';background:' . $kl . '" title="' . $l . ': ' . $p[$k] . '">' . $p[$k] . '</span>';
    $h .= '</div><div class="sv-legenda" style="margin-top:10px;flex-wrap:wrap">';
    foreach ($del as $k => [$l, $kl]) $h .= '<span><i style="width:10px;height:10px;border-radius:3px;background:' . $kl . '"></i>' . $l . ' ' . round($p[$k] / $t * 100) . '%</span>';
    return $h . '</div></div>';
}

// Wij vs elke aanbieder: prijsindex over gedeelde varianten
function g_index_aanbieders(array $S): string {
    $it = []; foreach ($S['aanb'] as $a) $it[] = ['naam' => '<b>' . e($a['naam']) . '</b>', 'sub' => $a['n'] . ' var.', 'v' => round($a['wij_vs'], 1), 'link' => '?tab=concurrenten&bron=' . $a['id']];
    usort($it, function ($x, $y) { return $y['v'] <=> $x['v']; });
    return '<p class="gr-uitleg">Prijs ' . wn() . ' vs hun prijs, mediaan over de varianten die beide voeren. <span style="color:#d64545">■</span> ' . wn() . ' duurder <span style="color:#2e9e4f">■</span> ' . wn() . ' goedkoper</p>' . g_staven($it, 'g_pct', 190);
}

// Profiel per aanbieder: prijsniveau (x) tegen assortimentsdekking (y)
function g_profiel(array $S): string {
    if (!$S['aanb']) return '<p class="muted">Nog geen aanbieders vergeleken.</p>';
    $W = 560; $H = 400; $l = 44; $r = 16; $t = 14; $b = 36; $lim = 15;
    foreach ($S['aanb'] as $a) $lim = max($lim, ceil(abs($a['vs_markt']) / 5) * 5);
    $X = function ($v) use ($l, $r, $W, $lim) { $v = max(-$lim, min($lim, $v)); return $l + ($v + $lim) / (2 * $lim) * ($W - $l - $r); };
    $Y = function ($v) use ($t, $b, $H) { return $H - $b - $v / 100 * ($H - $t - $b); };
    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;display:block" font-family="inherit" font-size="11">';
    $s .= '<rect x="' . $l . '" y="' . $t . '" width="' . ($X(-5) - $l) . '" height="' . ($H - $t - $b) . '" fill="#eef6f0"/>';
    $s .= '<rect x="' . $X(5) . '" y="' . $t . '" width="' . ($W - $r - $X(5)) . '" height="' . ($H - $t - $b) . '" fill="#fbeeee"/>';
    foreach ([0, 25, 50, 75, 100] as $g) $s .= '<line x1="' . $l . '" x2="' . ($W - $r) . '" y1="' . $Y($g) . '" y2="' . $Y($g) . '" stroke="#e4e2dc"/><text x="' . ($l - 6) . '" y="' . ($Y($g) + 4) . '" text-anchor="end" fill="#5b6068">' . $g . '%</text>';
    foreach ([-$lim, -5, 0, 5, $lim] as $g) $s .= '<text x="' . $X($g) . '" y="' . ($H - $b + 16) . '" text-anchor="middle" fill="#5b6068">' . g_pct($g, 0) . '</text>';
    $s .= '<line x1="' . $X(0) . '" x2="' . $X(0) . '" y1="' . $t . '" y2="' . ($H - $b) . '" stroke="#8e3009" stroke-width="1.5"/>';
    $s .= '<text x="' . ($l + 6) . '" y="' . ($t + 14) . '" fill="#1d6a3d" font-weight="600">discounter</text><text x="' . ($W - $r - 6) . '" y="' . ($t + 14) . '" text-anchor="end" fill="#9b1c1c" font-weight="600">premium</text>';
    $s .= '<text x="' . (($l + $W - $r) / 2) . '" y="' . ($H - 4) . '" text-anchor="middle" fill="#5b6068">prijsniveau vs marktmediaan · verticaal: deel van het assortiment van ' . wn() . ' dat zij ook verkopen</text>';
    // wij
    $s .= '<circle cx="' . $X($S['index'] ?? 0) . '" cy="' . $Y(100) . '" r="7" fill="#1f4fd1" stroke="#fff" stroke-width="2"/><text x="' . ($X($S['index'] ?? 0) + 11) . '" y="' . ($Y(100) + 4) . '" fill="#1f4fd1" font-weight="700">' . wn() . '</text>';
    // labels zonder overlap: eerst rechts van de stip, anders links, anders een regel lager of hoger, met een lijntje naar de stip
    $dots = [[$X($S['index'] ?? 0), $Y(100)]]; foreach ($S['aanb'] as $a) $dots[] = [$X($a['vs_markt']), $Y($a['dekking'])];
    $boxes = [[$X($S['index'] ?? 0) + 9, $Y(100) - 8, $X($S['index'] ?? 0) + 34, $Y(100) + 6]];
    $botst = function ($bx) use (&$boxes, &$dots, $l, $W, $r, $t, $H, $b) {
        if ($bx[0] < $l + 2 || $bx[2] > $W - $r - 2 || $bx[1] < $t || $bx[3] > $H - $b) return true;
        foreach ($boxes as $o) if ($bx[0] < $o[2] + 3 && $bx[2] > $o[0] - 3 && $bx[1] < $o[3] + 2 && $bx[3] > $o[1] - 2) return true;
        foreach ($dots as $d) if ($d[0] > $bx[0] - 7 && $d[0] < $bx[2] + 7 && $d[1] > $bx[1] - 7 && $d[1] < $bx[3] + 7) return true;
        return false; };
    $A = $S['aanb']; uasort($A, function ($x, $y) { return $y['dekking'] <=> $x['dekking']; });
    $punten = ''; $labels = '';
    foreach ($A as $a) { $cx = $X($a['vs_markt']); $cy = $Y($a['dekking']); $bw = mb_strlen($a['naam']) * 5.9 + 4; $gekozen = null;
        foreach ([0, 14, -14, 28, -28, 42, -42, 56, -56] as $dy) { foreach (['r', 'l'] as $kant) {
            $x0 = $kant === 'r' ? $cx + 10 : $cx - 10 - $bw; $bx = [$x0, $cy + $dy - 8, $x0 + $bw, $cy + $dy + 5];
            if (!$botst($bx)) { $gekozen = [$bx, $kant, $dy]; break 2; } } }
        if (!$gekozen) $gekozen = [[$cx + 10, $cy - 8, $cx + 10 + $bw, $cy + 5], 'r', 0];
        [$bx, $kant, $dy] = $gekozen; $boxes[] = $bx;
        $tx = $kant === 'r' ? $bx[0] : $bx[2]; $ty = $cy + $dy + 4;
        if ($dy) $labels .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . ($kant === 'r' ? $bx[0] - 2 : $bx[2] + 2) . '" y2="' . ($cy + $dy) . '" stroke="#b9bcc2" stroke-width="1"/>';
        $labels .= '<text x="' . $tx . '" y="' . $ty . '" text-anchor="' . ($kant === 'r' ? 'start' : 'end') . '" fill="#1a1c1e" paint-order="stroke" stroke="#fff" stroke-width="3">' . e($a['naam']) . '</text>';
        $punten .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="6" fill="#5b6068" stroke="#fff" stroke-width="2"><title>' . e($a['naam']) . ': ' . g_pct($a['vs_markt']) . ' vs markt, verkoopt ' . round($a['dekking']) . '% van het assortiment van ' . wn() . '</title></circle>'; }
    $s .= $labels . $punten;
    $s .= '</svg><table class="lijst klein" style="margin-top:10px"><tr><th>Aanbieder</th><th>Profiel</th><th style="text-align:right">Vs markt</th><th style="text-align:right" title="Hoeveel procent van de vergeleken varianten van ' . wn() . ' zij ook verkopen">Deel van assortiment ' . wn() . '</th><th style="text-align:right" title="Bij hoeveel varianten zij de laagste prijs hebben">Goedkoopst</th></tr>';
    $A = $S['aanb']; usort($A, function ($x, $y) { return $x['vs_markt'] <=> $y['vs_markt']; });
    foreach ($A as $a) $s .= '<tr><td><b>' . e($a['naam']) . '</b></td><td>' . chip($a['profiel'], $a['profiel'] === 'discounter' ? 'ok' : ($a['profiel'] === 'premium' ? 'warn' : '')) . '</td><td class="num" style="text-align:right">' . g_pct($a['vs_markt']) . '</td><td class="num" style="text-align:right">' . round($a['dekking']) . '%</td><td class="num" style="text-align:right">' . $a['goedkoopst'] . '×</td></tr>';
    return $s . '</table>';
}

// Stunters: wie is het vaakst de goedkoopste, en de diepste prijzen onder de mediaan
function g_stunters(array $S): string {
    $A = array_values(array_filter($S['aanb'], function ($a) { return $a['goedkoopst'] > 0; })); usort($A, function ($x, $y) { return $y['goedkoopst'] <=> $x['goedkoopst']; });
    $h = '<p class="gr-uitleg">Aantal varianten waar zij de laagste prijs hebben, onder ' . wn() . ' en de rest</p>';
    if (!$A) $h .= '<p class="muted" style="margin:0 0 12px">Niemand is ergens goedkoper dan ' . wn() . '.</p>';
    $max = $A ? $A[0]['goedkoopst'] : 1;
    foreach ($A as $a) $h .= '<a class="gr-rij" style="grid-template-columns:minmax(0,170px) 1fr 50px" href="?tab=concurrenten&bron=' . $a['id'] . '"><span class="vnaam"><b>' . e($a['naam']) . '</b></span><span class="gr-bak"><span class="gr-staaf" style="left:0;background:#d64545;width:' . round($a['goedkoopst'] / $max * 100, 1) . '%"></span></span><span class="num" style="text-align:right">' . $a['goedkoopst'] . '</span></a>';
    $st = []; foreach ($S['aanb'] as $a) foreach ($a['stunts'] as $x) $st[] = $x + ['wie' => $a['naam']];
    usort($st, function ($x, $y) { return $x['onder'] <=> $y['onder']; });
    $h .= '<h3 style="margin:18px 0 6px;font-size:14px">Diepste prijzen <span class="muted" style="font-weight:500;font-size:12.5px">meer dan ' . drempel('stunter_pct') . '% onder de mediaan</span></h3>';
    if (!$st) return $h . '<p class="muted" style="margin:0">Geen prijzen meer dan ' . drempel('stunter_pct') . '% onder de mediaan.</p>';
    $h .= '<table class="lijst klein"><tr><th>Variant</th><th>Wie</th><th style="text-align:right">Hun prijs</th><th style="text-align:right">Vs mediaan</th><th style="text-align:right">Prijs ' . wn() . '</th></tr>';
    foreach (array_slice($st, 0, 10) as $x) $h .= '<tr onclick="location.href=\'?tab=producten&p=' . (int)$x['m']['pid'] . '#v' . (int)$x['m']['ons']['vid'] . '\'" style="cursor:pointer"><td style="white-space:normal">' . e($x['m']['naam']) . '</td><td>' . e($x['wie']) . '</td><td class="num" style="text-align:right">' . eur($x['prijs']) . '</td><td class="num" style="text-align:right;color:#b42318">' . g_pct($x['onder']) . '</td><td class="num" style="text-align:right">' . eur($x['m']['ons']['prijs']) . '</td></tr>';
    return $h . '</table>' . (count($st) > 10 ? '<p class="muted" style="font-size:12.5px;margin:8px 0 0">+ ' . (count($st) - 10) . ' meer</p>' : '');
}

// Heatmap: elke variant × elke aanbieder, % van hun prijs t.o.v. de onze (rood = zij goedkoper, dus wij duurder)
function g_heatmap(array $met, array $S): string {
    $A = $S['aanb']; if (!$A || !$met) return '<p class="muted">Nog niets te vergelijken.</p>';
    uasort($A, function ($x, $y) { return $y['n'] <=> $x['n']; });
    $h = '<p class="gr-uitleg">Per variant hun prijs vs die van ' . wn() . '. <span style="color:#d64545">■</span> zij goedkoper, ' . wn() . ' duurder · <span style="color:#2e9e4f">■</span> zij duurder · leeg = voeren ze niet</p><div class="hm-wrap"><table class="hm"><tr><th class="hm-v">Variant</th><th>Prijs ' . wn() . '</th>';
    foreach ($A as $a) $h .= '<th title="' . e($a['naam']) . '">' . e($a['naam']) . '</th>';
    $h .= '</tr>'; $vorige = null;
    foreach ($met as $m) {
        if ($vorige !== $m['pid']) { $h .= '<tr class="hm-p"><td colspan="' . (count($A) + 2) . '">' . e(korte_titel($m['ons']['ptitel'])) . '</td></tr>'; $vorige = $m['pid']; }
        $h .= '<tr><td class="hm-v"><a href="?tab=producten&p=' . (int)$m['pid'] . '#v' . (int)$m['ons']['vid'] . '">' . e(blok_label($m['sleutel'] ?? (string)$m['ons']['inhoud_std'])) . '</a></td><td class="num">' . eur($m['ons']['prijs']) . '</td>';
        foreach ($A as $bid => $a) { if (!isset($m['perBron'][$bid])) { $h .= '<td class="hm-leeg"></td>'; continue; }
            $p = (float)$m['perBron'][$bid]['prijs']; $d = ($p - $m['ons']['prijs']) / $m['ons']['prijs'] * 100; $i = min(1, abs($d) / 15);
            $bg = abs($d) < 0.5 ? '#eeede8' : ($d < 0 ? 'rgba(214,69,69,' . round(0.15 + 0.75 * $i, 2) . ')' : 'rgba(46,158,79,' . round(0.15 + 0.75 * $i, 2) . ')');
            $h .= '<td style="background:' . $bg . ';color:' . ($i > 0.55 ? '#fff' : 'var(--ink)') . '" title="' . e($a['naam']) . ': ' . eur($p) . '">' . g_pct($d, 0) . '</td>'; }
        $h .= '</tr>';
    }
    return $h . '</table></div>';
}

// Best verkocht: per product onze positie vs de mediaan, gesorteerd op omzet
function g_bestverkocht(array $met, array $om, array $mg = []): string {
    $P = []; foreach ($met as $m) { $e = (string)($m['ons']['ext_id'] ?? ''); if (!isset($om[$e])) continue; $pid = $m['pid'];
        $P[$pid]['naam'] = korte_titel($m['ons']['ptitel']); $P[$pid]['omzet'] = (float)$om[$e]; $P[$pid]['v'][] = $m['vsmed'];
        $mm = g_marge_bij($mg[(string)($m['ons']['vext'] ?? '')] ?? null, (float)$m['ons']['prijs']); if ($mm !== null) $P[$pid]['mg'][] = $mm; }
    if (!$P) return '<p class="muted" style="margin:0">Nog geen omzetdata. Klik in ' . wn() . ' op "Best verkochte producten bijwerken uit Lightspeed".</p>';
    uasort($P, function ($x, $y) { return $y['omzet'] <=> $x['omzet']; });
    $P = array_slice($P, 0, 15, true); $max = 1; foreach ($P as $x) $max = max($max, abs(g_mediaan($x['v'])));
    $h = '<p class="gr-uitleg">Top 15 op omzet (' . omzet_periode() . ', hoogste bovenaan). Per product de prijs van ' . wn() . ' vs de mediaan (mediaan over de varianten) en de marge. Rood bij een hoge omzet = daar kost een te hoge prijs het meest.</p>';
    $h .= '<div class="bv-rij bv-kop"><span>Product · omzet</span><span></span><span style="text-align:right;white-space:nowrap">Vs mediaan</span><span style="text-align:right">Marge</span></div>';
    foreach ($P as $pid => $x) { $v = round(g_mediaan($x['v']), 1); $kl = g_kleur($v); $w = round(abs($v) / $max * 50, 1);
        $mgt = empty($x['mg']) ? '<span class="muted">—</span>' : (round(min($x['mg'])) === round(max($x['mg'])) ? round(min($x['mg'])) . '%' : round(min($x['mg'])) . '–' . round(max($x['mg'])) . '%');
        $h .= '<a class="bv-rij" href="?tab=producten&p=' . $pid . '"><span class="bv-naam">' . e($x['naam']) . ' <span class="muted" style="font-size:12px">€' . number_format($x['omzet'] / 1000, 0, ',', '.') . 'K</span></span>'
            . '<span class="gr-bak"><span class="gr-nul"></span><span class="gr-staaf" style="background:' . $kl . ';width:' . max($w, 0.8) . '%;' . ($v >= 0 ? 'left:50%' : 'right:50%') . '"></span></span>'
            . '<span class="num" style="text-align:right;color:' . ($kl === '#b9bcc2' ? 'var(--muted)' : $kl) . '">' . g_pct($v) . '</span><span class="num" style="text-align:right' . (!empty($x['mg']) && min($x['mg']) < g_minv() ? ';color:#b42318' : '') . '">' . $mgt . '</span></a>'; }
    return $h;
}


// ================= Kansen, risico's en marktgedrag =================

// Prijsadvies: waar kunnen we omhoog (wij goedkoopst met ruimte) en waar moeten we omlaag (duidelijk boven de mediaan)
function g_advies(array $met, array $om, array $mg = []): string {
    $r = [];
    foreach ($met as $m) { $wij = (float)$m['ons']['prijs']; $low = (float)$m['laagste']['prijs']; $med = (float)$m['med'];
        $o = (float)($om[(string)($m['ons']['ext_id'] ?? '')] ?? 0);
        if ($wij < $low * 0.98) { $nieuw = floor(($low - 0.05) * 20) / 20; $r[] = ['m' => $m, 'soort' => 'omhoog', 'nieuw' => $nieuw, 'd' => $nieuw - $wij, 'waarom' => wn() . ' goedkoopst, volgende is ' . e($m['laagste']['bnaam']) . ' ' . eur($low), 'o' => $o]; }
        elseif ($m['vsmed'] > 2) $r[] = ['m' => $m, 'soort' => 'omlaag', 'nieuw' => $med, 'd' => $med - $wij, 'waarom' => g_pct($m['vsmed']) . ' boven de mediaan', 'o' => $o];
    }
    if (!$r) return '<p class="muted" style="margin:0">Nergens duidelijke ruimte of risico: de prijzen van ' . wn() . ' zitten overal binnen 2% van de markt.</p>';
    usort($r, function ($x, $y) { return ($y['o'] > 0 ? 1 : 0) <=> ($x['o'] > 0 ? 1 : 0) ?: abs($y['d'] / max(1, $y['m']['ons']['prijs'])) <=> abs($x['d'] / max(1, $x['m']['ons']['prijs'])); });
    $up = count(array_filter($r, function ($x) { return $x['soort'] === 'omhoog'; }));
    $h = '<p class="gr-uitleg">' . $up . ' varianten met ruimte omhoog · ' . (count($r) - $up) . ' varianten duidelijk boven de mediaan. Voorstel op marktprijs, zonder verzending; de beslissing ligt bij de shop. <span style="color:#8a4b00">⚠</span> = dunne markt: minder dan ' . drempel('min_aanbieders') . ' aanbieders, voorstel minder betrouwbaar.</p>';
    $h .= '<table class="lijst klein"><tr><th>Variant</th><th style="text-align:right">Prijs ' . wn() . '</th><th>Voorstel</th><th style="text-align:right">Nieuwe prijs</th><th style="text-align:right">Verschil</th>' . ($mg ? '<th style="text-align:right">Marge nu</th><th style="text-align:right">Marge na</th>' : '') . '<th>Waarom</th></tr>';
    foreach (array_slice($r, 0, 20) as $x) { $m = $x['m'];
        $h .= '<tr onclick="location.href=\'?tab=producten&p=' . (int)$m['pid'] . '#v' . (int)$m['ons']['vid'] . '\'" style="cursor:pointer"><td>' . e($m['naam']) . ($m['n'] < drempel('min_aanbieders') ? ' <span style="color:#8a4b00" title="Dunne markt">⚠</span>' : '') . '</td><td class="num" style="text-align:right">' . eur($m['ons']['prijs']) . '</td>'
            . '<td>' . ($x['soort'] === 'omhoog' ? chip('↑ ruimte omhoog', 'ok') : chip('↓ naar mediaan', 'warn')) . '</td><td class="num" style="text-align:right">' . eur($x['nieuw']) . '</td>'
            . '<td class="num" style="text-align:right;color:' . ($x['d'] > 0 ? '#1d6a3d' : '#b42318') . '">' . ($x['d'] > 0 ? '+' : '−') . eur(abs($x['d'])) . '</td>' . ($mg ? g_marge_cellen($mg[(string)($m['ons']['vext'] ?? '')] ?? null, $x['nieuw']) : '') . '<td class="muted">' . $x['waarom'] . '</td></tr>'; }
    return $h . '</table>' . (count($r) > 20 ? '<p class="muted" style="font-size:12.5px;margin:8px 0 0">+ ' . (count($r) - 20) . ' meer</p>' : '');
}

// Marge nu en marge bij de voorgestelde prijs (inkoop excl. btw uit Lightspeed); onder de minimummarge rood
function g_marge_cellen($v, float $nieuwIncl): string {
    if (!$v || $v['inkoop'] === null || $v['excl'] <= 0) return '<td class="muted" style="text-align:right">—</td><td class="muted" style="text-align:right">—</td>';
    $btw = 1 + btw_eigen() / 100; $na = ($nieuwIncl / $btw - $v['inkoop']) / ($nieuwIncl / $btw) * 100;
    $c = function ($p) { return '<td class="num" style="text-align:right' . ($p < g_minv() ? ';color:#b42318' : '') . '">' . round($p) . '%</td>'; };
    return $c($v['pct']) . $c($na);
}

// Hoe druk is de markt: drie groepen in gewone taal, één balk, en per groep welke varianten erin zitten
function g_drukte(array $plat): string {
    $G = ['alleen' => ['Alleen ' . wn(), 'niemand anders verkoopt deze variant: prijs zelf bepalen', '#1f4fd1', []],
          'weinig' => ['1 of 2 concurrenten', 'dunne markt: de mediaan zegt nog weinig, voorzichtig mee omgaan', '#e0a33c', []],
          'druk'   => ['3 of meer concurrenten', 'echte markt: mediaan en positie zijn betrouwbaar', '#5b6068', []]];
    foreach ($plat as $m) { $k = $m['n'] === 0 ? 'alleen' : ($m['n'] <= 2 ? 'weinig' : 'druk'); $G[$k][3][] = $m; }
    $t = max(1, count($plat));
    $h = '<p class="gr-uitleg">Van de ' . count($plat) . ' varianten van ' . wn() . ' in de radar: hoeveel concurrenten verkopen dezelfde variant?</p><div class="gr-stapel" style="height:34px;margin-bottom:14px">';
    foreach ($G as $g) if ($g[3]) $h .= '<span style="flex:' . count($g[3]) . ';background:' . $g[2] . '" title="' . e($g[0]) . '">' . count($g[3]) . '</span>';
    $h .= '</div>';
    foreach ($G as $k => $g) { $n = count($g[3]);
        $h .= '<details class="dr-g"' . ($k === 'alleen' ? '' : '') . '><summary><i style="background:' . $g[2] . '"></i><b>' . e($g[0]) . '</b><span class="num">' . $n . ' var. · ' . round($n / $t * 100) . '%</span><small>' . e($g[1]) . '</small></summary>';
        if ($n) { $h .= '<ul class="kl">'; foreach ($g[3] as $m) $h .= '<li><a href="?tab=producten&p=' . (int)$m['pid'] . '#v' . (int)$m['ons']['vid'] . '">' . e($m['naam']) . '</a>' . ($m['n'] ? ' <span class="muted">· ' . $m['n'] . ' concurrent' . ($m['n'] > 1 ? 'en' : '') . '</span>' : '') . '</li>'; $h .= '</ul>'; }
        $h .= '</details>'; }
    return $h;
}

// Prijsspreiding: per variant een balk van laagste (links) tot hoogste prijs (rechts), onze prijs als stip, % spreiding erachter
function g_spreiding(array $met): string {
    $it = []; foreach ($met as $m) { $alle = array_merge([(float)$m['ons']['prijs']], $m['p']); if (count($alle) < 2) continue; $lo = min($alle); $hi = max($alle); $it[] = ['m' => $m, 'v' => ($hi - $lo) / $lo * 100, 'lo' => $lo, 'hi' => $hi]; }
    usort($it, function ($x, $y) { return $y['v'] <=> $x['v']; });
    if (!$it) return '<p class="muted">Nog niets te vergelijken.</p>';
    $h = '<p class="gr-uitleg">Grote spreiding = de markt is het oneens: ruimte om te verhogen, of een fout bij een concurrent (eerst checken). <span style="color:#1f4fd1">●</span> = prijs ' . wn() . '</p>';
    foreach (array_slice($it, 0, 12) as $x) { $m = $x['m']; $wx = $x['hi'] > $x['lo'] ? ((float)$m['ons']['prijs'] - $x['lo']) / ($x['hi'] - $x['lo']) * 100 : 50;
        $h .= '<a class="sp-rij" href="?tab=producten&p=' . (int)$m['pid'] . '#v' . (int)$m['ons']['vid'] . '"><span class="vnaam">' . e($m['naam']) . '</span>'
            . '<span class="sp-balk"><span class="num">' . eur($x['lo']) . '</span><span class="sp-bak"><span class="sp-lijn"></span><span class="sp-wij" style="left:' . round($wx, 1) . '%" title="' . wn() . ' ' . eur($m['ons']['prijs']) . '"></span></span><span class="num">' . eur($x['hi']) . '</span></span>'
            . '<span class="num" style="text-align:right">' . number_format($x['v'], 0, ',', '') . '%</span></a>'; }
    return $h;
}



// Prijswijzigingen: wat is er veranderd sinds de vorige meting, per aanbieder
function g_wijzigingen(array $conc): string {
    $ids = array_map(function ($b) { return (int)$b['id']; }, $conc); if (!$ids) return '<p class="muted">Geen aanbieders.</p>';
    $rows = db()->query('SELECT b.id bid, b.naam, p.titel pt, v.titel vt, v.id vid, m.prijs, m.tijd, m.run_id FROM meting m JOIN variant v ON v.id=m.variant_id JOIN product p ON p.id=v.product_id JOIN bron b ON b.id=p.bron_id WHERE b.id IN (' . implode(',', $ids) . ') ORDER BY v.id, m.tijd, m.id')->fetchAll();
    $laatst = []; $wijz = []; $per = [];
    foreach ($rows as $r) { $v = (int)$r['vid'];
        if (isset($laatst[$v]) && abs($laatst[$v]['prijs'] - $r['prijs']) > 0.005 && $laatst[$v]['run_id'] != $r['run_id']) { $d = ($r['prijs'] - $laatst[$v]['prijs']) / $laatst[$v]['prijs'] * 100;
            $wijz[] = ['naam' => $r['naam'], 'var' => volle_naam($r['pt'], $r['vt']), 'oud' => $laatst[$v]['prijs'], 'nieuw' => $r['prijs'], 'd' => $d, 'tijd' => $r['tijd']];
            $per[$r['naam']][$d > 0 ? 'op' : 'neer'] = ($per[$r['naam']][$d > 0 ? 'op' : 'neer'] ?? 0) + 1; }
        $laatst[$v] = $r; }
    $h = '<p class="gr-uitleg">Prijsveranderingen tussen twee metingen. Wie vaak wijzigt, prijst actief (of volgt een leverancier).</p>';
    if (!$wijz) return $h . '<p class="muted" style="margin:0">Nog geen wijzigingen gezien. Dit vult zich vanaf de tweede wekelijkse update.</p>';
    foreach ($per as $n => $x) $h .= '<div class="gr-rij" style="grid-template-columns:minmax(0,170px) 1fr"><span class="vnaam"><b>' . e($n) . '</b></span><span><span style="color:#b42318">↑ ' . ($x['op'] ?? 0) . ' duurder</span> &nbsp; <span style="color:#1d6a3d">↓ ' . ($x['neer'] ?? 0) . ' goedkoper</span></span></div>';
    usort($wijz, function ($x, $y) { return strcmp($y['tijd'], $x['tijd']); });
    $h .= '<table class="lijst klein" style="margin-top:10px"><tr><th>Wanneer</th><th>Wie</th><th>Variant</th><th style="text-align:right">Was</th><th style="text-align:right">Nu</th></tr>';
    foreach (array_slice($wijz, 0, 12) as $w) $h .= '<tr><td class="muted">' . e(substr($w['tijd'], 0, 10)) . '</td><td>' . e($w['naam']) . '</td><td>' . e($w['var']) . '</td><td class="num" style="text-align:right">' . eur($w['oud']) . '</td><td class="num" style="text-align:right;color:' . ($w['d'] > 0 ? '#b42318' : '#1d6a3d') . '">' . eur($w['nieuw']) . '</td></tr>';
    return $h . '</table>';
}

// Assortimentsgat: wat voeren zij dat wij niet (kunnen koppelen)
function g_gat(array $conc, array $concRows, array $eigenAlle): string {
    $h = '<p class="gr-uitleg">Hun producten van dit merk die aan niets in het assortiment van ' . wn() . ' gekoppeld zijn: kansen om toe te voegen, of een naam die afwijkt</p>'; $iets = false;
    foreach ($conc as $cb) { $bid = (int)$cb['id']; $los = [];
        foreach ($concRows as $c) if ((int)$c['bid'] === $bid && !koppel($c, $eigenAlle)) $los[korte_titel($c['ptitel'])] = 1;
        if (!$los) continue; $iets = true; ksort($los);
        $h .= '<details class="gat"><summary><b>' . e($cb['naam']) . '</b> <span class="muted">' . count($los) . ' producten</span></summary><p class="muted" style="font-size:12.5px;margin:6px 0 10px">' . e(implode(' · ', array_keys($los))) . '</p></details>'; }
    return $iets ? $h : $h . '<p class="muted" style="margin:0">Alles wat zij voeren staat ook bij ' . wn() . '.</p>';
}

// Afwijkers: prijzen ver van de mediaan, meestal een verkeerde koppeling of een ander formaat
function g_afwijkers(array $met): string {
    $r = []; foreach ($met as $m) foreach ($m['perBron'] as $c) { $d = ($c['prijs'] - $m['med']) / $m['med'] * 100; if ($m['n'] >= 2 && abs($d) > drempel('afwijker_pct')) $r[] = ['m' => $m, 'c' => $c, 'd' => $d]; }
    $h = '<p class="gr-uitleg">Prijzen meer dan ' . drempel('afwijker_pct') . '% van de mediaan af. Vaak een verkeerde koppeling, een ander formaat of een set. Controleer en kies zo nodig een andere variant.</p>';
    if (!$r) return $h . '<p class="muted" style="margin:0">Geen opvallende afwijkers.</p>';
    $h .= '<table class="lijst klein"><tr><th>Variant ' . wn() . '</th><th>Aanbieder</th><th>Hun variant</th><th style="text-align:right">Hun prijs</th><th style="text-align:right">Vs mediaan</th></tr>';
    foreach ($r as $x) $h .= '<tr onclick="location.href=\'?tab=producten&p=' . (int)$x['m']['pid'] . '#v' . (int)$x['m']['ons']['vid'] . '\'" style="cursor:pointer"><td>' . e($x['m']['naam']) . '</td><td>' . e($x['c']['bnaam']) . '</td><td class="muted">' . e(volle_naam($x['c']['ptitel'], $x['c']['vtitel'])) . '</td><td class="num" style="text-align:right">' . eur($x['c']['prijs']) . '</td><td class="num" style="text-align:right">' . g_pct($x['d']) . '</td></tr>';
    return $h . '</table>';
}

// Prijsladder: per variant alle aanbieders van goedkoop naar duur, ons blik als eigen blauw blokje op zijn plek
function g_ladder(array $met, array $S): string {
    if (!$met) return '<p class="muted">Nog niets te vergelijken.</p>';
    $max = 1; foreach ($met as $m) $max = max($max, $m['n'] + 1);
    $h = '<div class="hm-wrap"><table class="hm lad"><tr><th class="hm-v">Variant</th>';
    for ($i = 1; $i <= $max; $i++) $h .= '<th>' . ($i === 1 ? 'goedkoopst' : ($i === $max ? 'duurst' : $i)) . '</th>';
    $h .= '</tr>'; $vorige = null;
    foreach ($met as $m) {
        if ($vorige !== $m['pid']) { $h .= '<tr class="hm-p"><td colspan="' . ($max + 1) . '">' . e(korte_titel($m['ons']['ptitel'])) . '</td></tr>'; $vorige = $m['pid']; }
        $wij = (float)$m['ons']['prijs'];
        $rij = [['wij' => true, 'p' => $wij, 'naam' => wn()]];
        foreach ($m['perBron'] as $c) $rij[] = ['wij' => false, 'p' => (float)$c['prijs'], 'naam' => $c['bnaam']];
        // op prijs; bij gelijke prijs staan wij vooraan (gedeelde plek)
        usort($rij, function ($a, $b) { return abs($a['p'] - $b['p']) <= 0.005 ? ($b['wij'] <=> $a['wij']) : ($a['p'] <=> $b['p']); });
        $h .= '<tr><td class="hm-v"><a href="?tab=producten&p=' . (int)$m['pid'] . '#v' . (int)$m['ons']['vid'] . '">' . e(blok_label($m['sleutel'] ?? (string)$m['ons']['inhoud_std'])) . '</a></td>';
        foreach ($rij as $x) {
            if ($x['wij']) { $h .= '<td class="lad-wij" title="Prijs ' . wn() . '">' . wn() . '<b>' . eur($wij) . '</b></td>'; continue; }
            $d = ($x['p'] - $wij) / $wij * 100; $i = min(1, abs($d) / 15);
            $bg = abs($d) < 0.5 ? '#eeede8' : ($d < 0 ? 'rgba(214,69,69,' . round(0.15 + 0.75 * $i, 2) . ')' : 'rgba(46,158,79,' . round(0.15 + 0.75 * $i, 2) . ')');
            $h .= '<td style="background:' . $bg . ';color:' . ($i > 0.55 ? '#fff' : 'var(--ink)') . '" title="' . e($x['naam']) . ': ' . eur($x['p']) . '"><span class="lad-n">' . e($x['naam']) . '</span><b>' . g_pct($d, 0) . '</b></td>'; }
        for ($k = count($rij); $k < $max; $k++) $h .= '<td class="lad-leeg"></td>';
        $h .= '</tr>';
    }
    return $h . '</table></div>';
}

// ================= Marge × markt (inkoop uit Lightspeed, prijzen gemeten) =================
// Marge in % van de verkoopprijs excl. btw als wij $prijsIncl zouden rekenen; null als de inkoop ontbreekt
function g_marge_bij($v, $prijsIncl) {
    if (!$v || $v['inkoop'] === null || $prijsIncl === null || $prijsIncl <= 0) return null;
    $ex = $prijsIncl / (1 + btw_eigen() / 100);
    return ($ex - $v['inkoop']) / $ex * 100;
}
function g_met_marge(array $met, array $mg): array {
    $r = []; foreach ($met as $m) { $v = $mg[(string)($m['ons']['vext'] ?? '')] ?? null; if (!$v || $v['inkoop'] === null) continue;
        $r[] = ['m' => $m, 'v' => $v, 'nu' => g_marge_bij($v, (float)$m['ons']['prijs']), 'med' => g_marge_bij($v, (float)$m['med']), 'laag' => g_marge_bij($v, (float)$m['laagste']['prijs'])]; }
    return $r;
}
function g_marge_kpis(array $R, array $om): string {
    if (!$R) return '';
    $gem = function ($k) use ($R, $om) { $s = 0; $w = 0; foreach ($R as $x) { $o = (float)($om[(string)($x['m']['ons']['ext_id'] ?? '')] ?? 0); $wt = $o > 0 ? $o : 1; $s += $x[$k] * $wt; $w += $wt; } return $w ? $s / $w : null; };
    $nu = $gem('nu'); $med = $gem('med'); $laag = $gem('laag'); $onder = count(array_filter($R, function ($x) { return $x['laag'] < 0; }));
    $kl = function ($p) { return $p < g_minv() ? '#b42318' : 'var(--ink)'; }; $gw = $om ? 'gewogen naar omzet' : 'gemiddeld per variant';
    return '<div class="kpis">'
        . '<div class="kpi"><small>Marge ' . wn() . ' nu</small><div style="color:' . $kl($nu) . '">' . round($nu) . '%</div><p>' . $gw . ', ' . count($R) . ' varianten met inkoopprijs</p></div>'
        . '<div class="kpi"><small>Bij prijs = mediaan</small><div style="color:' . $kl($med) . '">' . round($med) . '%</div><p>marge bij de marktmediaan</p></div>'
        . '<div class="kpi"><small>Bij prijs = goedkoopste</small><div style="color:' . $kl($laag) . '">' . round($laag) . '%</div><p>marge bij de laagste concurrentprijs</p></div>'
        . '<div class="kpi"><small>Onder inkoop ' . wn() . '</small><div style="color:' . ($onder ? '#b42318' : 'var(--ink)') . '">' . $onder . '</div><p>varianten waar een concurrent onder de inkoopprijs van ' . wn() . ' verkoopt</p></div></div>';
}
// Scatter: x = onze prijs vs mediaan, y = onze marge. Vier vakken met wat je ermee doet.
function g_marge_matrix(array $R): string {
    if (!$R) return '<p class="muted" style="margin:0">Nog geen marge. Klik in ' . wn() . ', Dekking, op "Bereken marge per product".</p>';
    $W = 1100; $H = 520; $l = 60; $r = 20; $t = 20; $b = 40; $xl = 10; $yt = 50;
    $yb = 10; foreach ($R as $x) { $xl = max($xl, ceil(abs($x['m']['vsmed']) / 5) * 5); $yt = max($yt, ceil($x['nu'] / 10) * 10); $yb = min($yb, floor(($x['nu'] - 2) / 10) * 10); }
    $yb = max(-50, $yb); // onderkant: 10%, of lager als er varianten met minder marge zijn (meer ruimte voor de stippen)
    $X = function ($v) use ($l, $r, $W, $xl) { $v = max(-$xl, min($xl, $v)); return round($l + ($v + $xl) / (2 * $xl) * ($W - $l - $r), 1); };
    $Y = function ($v) use ($t, $b, $H, $yt, $yb) { $v = max($yb, min($yt, $v)); return round($H - $b - ($v - $yb) / ($yt - $yb) * ($H - $t - $b), 1); };
    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;display:block" font-family="inherit" font-size="12">';
    // vakken
    $mm = g_min(); $ys = $mm === null ? $H - $b : $Y($mm); $mt = $mm === null ? '' : ' (' . round($mm) . '%)';
    $vak = [[$l, $t, $X(0) - $l, $ys - $t, '#f1f7f2', $mm === null ? 'goedkoper dan de mediaan' : 'goedkoop, boven de minimummarge' . $mt, 'start', $l + 8, $t + 16],
            [$X(0), $t, $W - $r - $X(0), $ys - $t, '#f3f2ed', $mm === null ? 'duurder dan de mediaan' : 'duur, boven de minimummarge' . $mt, 'end', $W - $r - 8, $t + 16]];
    if ($mm !== null) { $vak[] = [$l, $ys, $X(0) - $l, $H - $b - $ys, '#fdf6e7', 'goedkoop én onder de minimummarge: verhogen?', 'start', $l + 8, $H - $b - 8];
                        $vak[] = [$X(0), $ys, $W - $r - $X(0), $H - $b - $ys, '#fbeeee', 'duur én onder de minimummarge: probleem', 'end', $W - $r - 8, $H - $b - 8]; }
    foreach ($vak as $v) $s .= '<rect x="' . $v[0] . '" y="' . $v[1] . '" width="' . $v[2] . '" height="' . $v[3] . '" fill="' . $v[4] . '"/><text x="' . $v[7] . '" y="' . $v[8] . '" text-anchor="' . $v[6] . '" fill="#5b6068" font-weight="600">' . $v[5] . '</text>';
    for ($g = $yb; $g <= $yt; $g += 10) $s .= '<line x1="' . $l . '" x2="' . ($W - $r) . '" y1="' . $Y($g) . '" y2="' . $Y($g) . '" stroke="#e4e2dc" stroke-width="1"/><text x="' . ($l - 6) . '" y="' . ($Y($g) + 4) . '" text-anchor="end" fill="#5b6068">' . $g . '%</text>';
    foreach ([-$xl, -$xl / 2, 0, $xl / 2, $xl] as $g) $s .= '<text x="' . $X($g) . '" y="' . ($H - $b + 18) . '" text-anchor="middle" fill="#5b6068">' . g_pct($g, 0) . '</text>';
    $s .= '<line x1="' . $X(0) . '" x2="' . $X(0) . '" y1="' . $t . '" y2="' . ($H - $b) . '" stroke="#8e3009" stroke-width="1.5"/>';
    $s .= '<text x="' . (($l + $W - $r) / 2) . '" y="' . ($H - 4) . '" text-anchor="middle" fill="#5b6068">← goedkoper dan de mediaan · prijs ' . wn() . ' vs mediaan · duurder dan de mediaan →</text>';
    $s .= '<text transform="translate(14 ' . (($t + $H - $b) / 2) . ') rotate(-90)" text-anchor="middle" fill="#5b6068">↑ marge ' . wn() . ' bij de huidige prijs</text>';
    // stippen genummerd; varianten op (bijna) dezelfde plek delen één nummer. Legenda eronder, per vak.
    $cl = []; foreach ($R as $x) { $k = round($x['m']['vsmed'] * 2) . '|' . round($x['nu']); $cl[$k][] = $x; }
    $cl = array_values($cl);
    usort($cl, function ($a, $b) { return [$b[0]['m']['vsmed'] > 0.25, $b[0]['m']['vsmed'], -$b[0]['nu']] <=> [$a[0]['m']['vsmed'] > 0.25, $a[0]['m']['vsmed'], -$a[0]['nu']]; });
    $pt = ''; $leg = ['probleem' => [], 'zakken' => [], 'gezond' => [], 'verhogen' => []];
    $kort = function ($x) { $n = preg_replace('/^' . preg_quote(merk(), '/') . '\s+/i', '', korte_titel($x['m']['ons']['ptitel'])); return $n . ' ' . blok_label($x['m']['sleutel'] ?? (string)$x['m']['ons']['inhoud_std']); };
    // bolletjes mogen elkaar nooit overlappen: schuif zo nodig op (afwisselend omhoog/omlaag, dan opzij) met een lijntje naar de echte plek
    $geplaatst = []; $rr = 10; $lijn = '';
    foreach ($cl as $i => $g) { $nr = $i + 1; $x = $g[0]; $ox = $X($x['m']['vsmed']); $oy = $Y($x['nu']); $cx = $ox; $cy = $oy;
        $vrij = function ($px, $py) use (&$geplaatst, $rr) { foreach ($geplaatst as $q) if (($px - $q[0]) ** 2 + ($py - $q[1]) ** 2 < (2 * $rr + 3) ** 2) return false; return true; };
        if (!$vrij($cx, $cy)) { $gevonden = false;
            for ($st = 1; $st <= 12 && !$gevonden; $st++) foreach ([[0, -1], [0, 1], [1, 0], [-1, 0], [1, -1], [-1, -1], [1, 1], [-1, 1]] as $d) { $px = $ox + $d[0] * $st * ($rr * 2 + 3) * .8; $py = $oy + $d[1] * $st * ($rr * 2 + 3) * .8;
                if ($px < $l + $rr || $px > $W - $r - $rr || $py < $t + $rr || $py > $H - $b - $rr) continue; if ($vrij($px, $py)) { $cx = round($px, 1); $cy = round($py, 1); $gevonden = true; break; } } }
        $geplaatst[] = [$cx, $cy];
        if ($cx != $ox || $cy != $oy) $lijn .= '<line x1="' . $ox . '" y1="' . $oy . '" x2="' . $cx . '" y2="' . $cy . '" stroke="#9aa0a6" stroke-width="1"/><circle cx="' . $ox . '" cy="' . $oy . '" r="2.5" fill="#5b6068"/>';
        $kl = $x['nu'] < g_minv() ? '#b42318' : ($x['m']['vsmed'] > 0.5 ? '#d64545' : ($x['m']['vsmed'] < -0.5 ? '#2e9e4f' : '#5b6068'));
        $tt = implode("\n", array_map(function ($y) { return $y['m']['naam'] . ': marge ' . round($y['nu']) . '%, ' . g_pct($y['m']['vsmed']) . ' vs mediaan'; }, $g));
        $pt .= '<a href="?tab=producten&p=' . (int)$x['m']['pid'] . '#v' . (int)$x['m']['ons']['vid'] . '"><circle cx="' . $cx . '" cy="' . $cy . '" r="10" fill="' . $kl . '" stroke="#fff" stroke-width="1.5"><title>' . e($tt) . '</title></circle><text x="' . $cx . '" y="' . ($cy + 4) . '" text-anchor="middle" fill="#fff" font-size="11" font-weight="700" pointer-events="none">' . $nr . '</text></a>';
        $vak = $x['m']['vsmed'] > 0.25 ? ($x['nu'] < g_minv() ? 'probleem' : 'zakken') : ($x['nu'] < g_minv() ? 'verhogen' : 'gezond');
        $leg[$vak][] = '<li><b class="mx-nr" style="background:' . $kl . '">' . $nr . '</b><span>' . e(implode(' · ', array_map($kort, $g))) . '</span><span class="num" style="text-align:right">' . round($x['nu']) . '%</span><span class="num" style="text-align:right;color:' . g_kleur($x['m']['vsmed']) . '">' . g_pct($x['m']['vsmed']) . '</span></li>'; }
    $titels = $mm === null ? ['probleem' => '', 'zakken' => 'Duurder dan de mediaan', 'gezond' => 'Op of onder de mediaan', 'verhogen' => '']
        : ['probleem' => 'Duur én onder de minimummarge: probleem', 'zakken' => 'Duur, boven de minimummarge' . $mt, 'gezond' => 'Op of onder de mediaan, boven de minimummarge', 'verhogen' => 'Goedkoop én onder de minimummarge: verhogen?'];
    $lh = '<div class="mx-leg">'; foreach ($leg as $k => $items) if ($items) $lh .= '<div><h4>' . $titels[$k] . ' <span class="muted">(' . count($items) . ')</span></h4><ul><li class="mx-kop"><span>Nr</span><span>Product en inhoud</span><span style="text-align:right">Marge ' . wn() . '</span><span style="text-align:right">Vs mediaan</span></li>' . implode('', $items) . '</ul></div>'; $lh .= '</div>';
    return '<p class="gr-uitleg">Elke genummerde stip is een variant van ' . wn() . ': links/rechts = goedkoper/duurder dan de mediaan, hoog/laag = de marge. Het nummer staat in de lijst eronder met de productnaam, marge en % vs mediaan (varianten op dezelfde plek delen een nummer). ' . ($mm === null ? '<b style="color:#b42318">Stel eerst jullie minimale marge in</b> (' . wn() . ' → Dekking → Marge); dan verdeelt de grafiek in boven en onder dat minimum.' : 'Rode stippellijn = de minimale marge van ' . round($mm) . '% (instellen in ' . wn() . ' → Dekking).') . '</p>' . $s . $lijn . $pt . '</svg>' . $lh;
}
// Kun je de goedkoopste volgen: marge nu, bij de mediaan en bij de laagste prijs
function g_volgen(array $R, array $om = []): string {
    if (!$R) return '<p class="muted" style="margin:0">Nog geen marge berekend.</p>';
    $c = function ($p) { return '<td class="num" style="text-align:right' . ($p < 0 ? ';color:#fff;background:#b42318;border-radius:4px' : ($p < g_minv() ? ';color:#b42318' : '')) . '">' . ($p < 0 ? 'onder inkoop' : round($p) . '%') . '</td>'; };
    // per product groeperen, varianten op inhoud; groepen sorteren kan op lastigst / meest verkocht / naam
    $P = []; foreach ($R as $x) { $pid = $x['m']['pid']; $P[$pid]['r'][] = $x; $P[$pid]['naam'] = korte_titel($x['m']['ons']['ptitel']); $P[$pid]['omzet'] = (float)($om[(string)($x['m']['ons']['ext_id'] ?? '')] ?? 0); $P[$pid]['laag'] = min($P[$pid]['laag'] ?? 999, $x['laag']); }
    uasort($P, function ($a, $b) { return $a['laag'] <=> $b['laag']; });
    $h = '<p class="gr-uitleg">Per variant: wat blijft er over bij een prijs gelijk aan de mediaan of de goedkoopste concurrent volgen? Varianten van hetzelfde product blijven bij elkaar. <span style="color:#8a4b00">⚠</span> = dunne markt: minder dan ' . drempel('min_aanbieders') . ' aanbieders, cijfers minder betrouwbaar.</p>';
    $h .= '<div class="sorteer" style="margin:0 0 10px"><span class="muted">Volgorde</span><button type="button" class="on" onclick="vlgSort(this,\'laag\',1)">Lastigst eerst</button><button type="button" onclick="vlgSort(this,\'omzet\',-1)"' . ($om ? '' : ' disabled title="Eerst de best verkochte producten ophalen" style="opacity:.4"') . '>Meest verkocht</button><button type="button" onclick="vlgSort(this,\'naam\',1)">Naam</button></div>';
    $h .= '<table class="lijst klein" id="vlg"><thead><tr><th>Variant</th><th style="text-align:right">Prijs ' . wn() . '</th><th style="text-align:right">Marge nu</th><th style="text-align:right">Bij mediaan</th><th style="text-align:right">Bij laagste</th><th>Laagste door</th></tr></thead>';
    foreach ($P as $p) { usort($p['r'], function ($a, $b) { return (float)$a['m']['ons']['inhoud_std'] <=> (float)$b['m']['ons']['inhoud_std']; });
        $h .= '<tbody data-laag="' . round($p['laag'], 2) . '" data-omzet="' . round($p['omzet']) . '" data-naam="' . e(strtolower($p['naam'])) . '">';
        foreach ($p['r'] as $x) { $m = $x['m'];
            $h .= '<tr onclick="location.href=\'?tab=producten&p=' . (int)$m['pid'] . '#v' . (int)$m['ons']['vid'] . '\'" style="cursor:pointer"><td>' . e($m['naam']) . ($m['n'] < drempel('min_aanbieders') ? ' <span style="color:#8a4b00" title="Dunne markt: minder dan ' . drempel('min_aanbieders') . ' aanbieders">⚠</span>' : '') . '</td><td class="num" style="text-align:right">' . eur($m['ons']['prijs']) . '</td>' . $c($x['nu']) . $c($x['med']) . $c($x['laag']) . '<td class="muted">' . e($m['laagste']['bnaam']) . ' ' . eur($m['laagste']['prijs']) . '</td></tr>'; }
        $h .= '</tbody>'; }
    return $h . '</table><script>function vlgSort(b,k,d){b.parentNode.querySelectorAll("button").forEach(function(x){x.classList.toggle("on",x===b);});var t=document.getElementById("vlg"),bs=[].slice.call(t.tBodies);bs.sort(function(p,q){var a=p.dataset[k],c=q.dataset[k];if(k!=="naam"){a=+a;c=+c;}return (a<c?-1:a>c?1:0)*d;});bs.forEach(function(x){t.appendChild(x);});}</script>';
}

// ================= Marktbeeld (Overzicht): hele markt in één grafiek =================
// Per variant één regel: x = % vs mediaan (mediaan = 0). Wij = blauwe ruit + balk vanaf 0 (rood duurder, groen goedkoper).
// Aanbieders = bolletjes in hun eigen kleur met naam + prijs; aanbieders op dezelfde prijs = één grijs bolletje met aantal.
function g_marktbeeld(array $MA, array $mg, array $conc): string {
    $rows = []; foreach ($MA as $pid => $ms) { $ms = array_values(array_filter($ms, function ($m) { return $m['n'] > 0; })); if ($ms) $rows[$pid] = $ms; }
    if (!$rows) return '';
    $pal = ['#7b4fb8', '#e07b24', '#12877f', '#8a5a2b', '#c2408f', '#6b7d1f', '#1f6f9f', '#b88a00', '#5b6068', '#a33b3b'];
    $kleur = []; $i = 0; foreach ($conc as $cb) $kleur[(int)$cb['id']] = $pal[$i++ % count($pal)];
    $lim = 5; foreach ($rows as $ms) foreach ($ms as $m) { $lim = max($lim, abs($m['vsmed'])); foreach ($m['perBron'] as $c) $lim = max($lim, abs(($c['prijs'] - $m['med']) / $m['med'] * 100)); }
    $lim = min(40, ceil(($lim + 2) / 5) * 5);
    $W = 1240; $L = 300; $R = 30; $HH = 36; $GAP = 18; $top = 40; $CY = 24; $LANE = 17;
    $X = function ($p) use ($L, $R, $W, $lim) { $p = max(-$lim, min($lim, $p)); return round($L + ($p + $lim) / (2 * $lim) * ($W - $L - $R), 1); };
    $stap = $lim > 20 ? 10 : 5;
    $raster = function ($y1, $y2) use ($lim, $stap, $X) { $o = ''; for ($g = -$lim; $g <= $lim; $g += $stap) if ($g != 0) $o .= '<line x1="' . $X($g) . '" x2="' . $X($g) . '" y1="' . $y1 . '" y2="' . $y2 . '" stroke="#efeee9"/>'; return $o; };
    $medl = function ($y1, $y2) use ($X) { return '<line x1="' . $X(0) . '" x2="' . $X(0) . '" y1="' . $y1 . '" y2="' . $y2 . '" stroke="#8e3009" stroke-width="2.2" opacity=".85"/>'; };
    // één variant tekenen op eigen coördinaten (y = 0 bovenkant); geeft [svg, hoogte]
    $variant = function ($pid, $m) use ($X, $L, $R, $W, $CY, $LANE, $mg, $kleur, $raster, $medl) {
        $cy = $CY; $wij = (float)$m['ons']['prijs']; $med = (float)$m['med'];
        $mp = g_marge_bij($mg[(string)($m['ons']['vext'] ?? '')] ?? null, $wij);
        $v = $m['vsmed']; $kl = g_kleur($v); $x0 = $X(0); $x1 = $X($v);
        $bg = '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $cy . '" y2="' . $cy . '" stroke="#c9c6bd"/>';
        $bg .= '<a href="?tab=producten&p=' . (int)$pid . '#v' . (int)$m['ons']['vid'] . '"><text x="28" y="' . ($cy + 4) . '" font-size="13.5" fill="#1a1c1e">' . e(blok_label($m['sleutel'] ?? (string)$m['ons']['inhoud_std'])) . ' <tspan fill="#5b6068">(' . $m['n'] . ')' . ($m['n'] < drempel('min_aanbieders') ? ' ⚠' : '') . '</tspan></text></a>';
        if ($mp !== null) $bg .= '<text x="' . ($L - 30) . '" y="' . ($cy + 4) . '" text-anchor="end" font-size="12.5" font-weight="600" fill="' . ($mp < g_minv() ? '#b42318' : '#1d6a3d') . '">marge ' . round($mp) . '%</text>';
        if (abs($v) >= 0.5) $bg .= '<rect x="' . min($x0, $x1) . '" y="' . ($cy - 5) . '" width="' . abs($x1 - $x0) . '" height="10" rx="3" fill="' . $kl . '" opacity=".75"/>';
        // aanbieders clusteren op dezelfde prijs; bolletjes altijd op de lijn, namen altijd onder de lijn
        $cl = []; foreach ($m['perBron'] as $bid => $c) $cl[number_format((float)$c['prijs'], 2)][] = ['bid' => $bid, 'naam' => $c['bnaam'], 'p' => (float)$c['prijs']];
        $labels = []; $pts = ''; $gelijk = $cl[number_format($wij, 2)] ?? []; unset($cl[number_format($wij, 2)]);
        $wx = $X($v); $bezetX = [[$wx, 0]];
        foreach ($cl as $g) { $p = $g[0]['p']; $px = $X(($p - $med) / $med * 100); $n = count($g);
            $dy = 0; // elk bolletje staat op de lijn; gelijke prijzen zijn al samengevoegd tot één bolletje met een getal
            $bezetX[] = [$px, $dy]; $pcy = $cy + $dy;
            $col = $n > 1 ? '#8a8f96' : ($kleur[$g[0]['bid']] ?? '#5b6068');
            $tt = implode(', ', array_map(function ($a) { return $a['naam']; }, $g)) . ': ' . eur($p) . ' (' . g_pct(($p - $med) / $med * 100) . ' vs mediaan, ' . g_pct(($p - $wij) / $wij * 100) . ' vs ' . wn() . ')';
            $pts .= '<g><title>' . e($tt) . '</title>' . ($dy ? '<line x1="' . $px . '" x2="' . $px . '" y1="' . $pcy . '" y2="' . $cy . '" stroke="#c9c6bd"/>' : '') . '<circle cx="' . $px . '" cy="' . $pcy . '" r="' . ($n > 1 ? 8 : 6) . '" fill="' . $col . '" stroke="#fff" stroke-width="1.5"/>' . ($n > 1 ? '<text x="' . $px . '" y="' . ($pcy + 3.5) . '" text-anchor="middle" font-size="10" font-weight="700" fill="#fff">' . $n . '</text>' : '') . '</g>';
            $labels[] = ['x' => $px, 't' => $n > 1 ? eur($p) . ' (' . implode(', ', array_map(function ($a) { return $a['naam']; }, $g)) . ')' : eur($p) . ' ' . $g[0]['naam'], 'c' => $col]; }
        $pts .= '<g><title>' . wn() . ': ' . e(eur($wij)) . ' (' . g_pct($v) . ' vs mediaan ' . e(eur($med)) . ')' . ($gelijk ? '. Zelfde prijs: ' . e(implode(', ', array_map(function ($a) { return $a['naam']; }, $gelijk))) : '') . '</title><rect x="' . ($wx - 6) . '" y="' . ($cy - 6) . '" width="12" height="12" transform="rotate(45 ' . $wx . ' ' . $cy . ')" fill="#1f4fd1" stroke="#fff" stroke-width="1.5"/></g>';
        $labels[] = ['x' => $wx, 't' => eur($wij) . ' ' . wn() . ($gelijk ? ' (' . implode(', ', array_map(function ($a) { return $a['naam']; }, $gelijk)) . ')' : ''), 'c' => '#1f4fd1', 'wij' => true];
        // namen onder de lijn, van links naar rechts; past een naam niet, dan een regel lager met een lijntje naar het bolletje
        usort($labels, function ($a, $b) { return $a['x'] <=> $b['x']; });
        $lanes = []; $lijnen = ''; $tx = '';
        foreach ($labels as $lb) { $bw = mb_strlen($lb['t']) * (!empty($lb['wij']) ? 6.5 : 6.1); $x0l = max($L + 2, min($W - $R - $bw, $lb['x'] - 3)); // tekst begint links bij het bolletje
            for ($k = 0; ; $k++) { $vrij = true; foreach ($lanes[$k] ?? [] as $o) if ($x0l < $o[1] + 8 && $x0l + $bw > $o[0] - 8) { $vrij = false; break; } if ($vrij) break; }
            $lanes[$k][] = [$x0l, $x0l + $bw]; $ly = $cy + 25 + $k * $LANE; $ankx = round($x0l + 3, 1);
            if ($k > 0 || abs($ankx - $lb['x']) > 2) $lijnen .= '<polyline points="' . $lb['x'] . ',' . ($cy + 7) . ' ' . $lb['x'] . ',' . ($ly - 12) . ($ankx != $lb['x'] ? ' ' . $ankx . ',' . ($ly - 12) : '') . '" fill="none" stroke="#c9c6bd"/>';
            $tx .= '<rect x="' . round($x0l - 3, 1) . '" y="' . ($ly - 10) . '" width="' . round($bw + 6, 1) . '" height="13" rx="3" fill="#fff"/><text x="' . round($x0l, 1) . '" y="' . $ly . '" font-size="11"' . (!empty($lb['wij']) ? ' font-weight="700"' : '') . ' fill="' . $lb['c'] . '">' . e($lb['t']) . '</text>'; }
        $h = $cy + 25 + (max(1, count($lanes)) - 1) * $LANE + 14;
        return [$raster(8, $h - 8) . $medl(0, $h) . $bg . $lijnen . $pts . $tx, $h];
    };
    $body = ''; $y = $top;
    foreach ($rows as $pid => $ms) {
        $vs = []; $bh = $HH; foreach ($ms as $m) { $vs[] = $variant($pid, $m); $bh += end($vs)[1]; }
        $body .= '<rect x="1" y="' . $y . '" width="' . ($W - 2) . '" height="' . $bh . '" rx="10" fill="#fff" stroke="#dcd9d0"/>'
            . '<path d="M1 ' . ($y + $HH) . ' V' . ($y + 10) . ' a9 9 0 0 1 9 -9 H' . ($W - 11) . ' a9 9 0 0 1 9 9 V' . ($y + $HH) . ' Z" fill="#f6f5f1"/>'
            . '<line x1="1" x2="' . ($W - 1) . '" y1="' . ($y + $HH) . '" y2="' . ($y + $HH) . '" stroke="#dcd9d0"/>'
            . '<text x="16" y="' . ($y + 23) . '" font-size="14" font-weight="700" fill="#1a1c1e">' . e(korte_titel($ms[0]['ons']['ptitel'])) . '</text>';
        $y += $HH;
        foreach ($vs as $i => [$svg, $h]) { if ($i) $body .= '<line x1="12" x2="' . ($W - 12) . '" y1="' . $y . '" y2="' . $y . '" stroke="#ecebe6"/>'; $body .= '<g transform="translate(0,' . $y . ')">' . $svg . '</g>'; $y += $h; }
        $y += $GAP;
    }
    $H = $y + 4;
    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;display:block" font-family="inherit" font-size="12">';
    for ($g = -$lim; $g <= $lim; $g += $stap) $s .= '<text x="' . $X($g) . '" y="' . ($top - 16) . '" text-anchor="middle" fill="' . ($g == 0 ? '#8e3009' : '#5b6068') . '"' . ($g == 0 ? ' font-weight="600"' : '') . '>' . ($g == 0 ? 'mediaan' : g_pct($g, 0)) . '</text>';
    $s .= '<text x="28" y="' . ($top - 16) . '" font-size="11" font-weight="600" fill="#5b6068" letter-spacing=".03em">VOLUME <tspan font-weight="400">(AANBIEDERS)</tspan></text>'
        . '<text x="' . ($L - 30) . '" y="' . ($top - 16) . '" text-anchor="end" font-size="11" font-weight="600" fill="#5b6068" letter-spacing=".03em">MARGE</text>';
    $s .= $body;
    $s .= '</svg>';
    $leg = '<div class="mb-leg"><span><i style="background:#1f4fd1;transform:rotate(45deg);border-radius:1px"></i><b>' . wn() . '</b></span>';
    foreach ($conc as $cb) $leg .= '<span><i style="background:' . $kleur[(int)$cb['id']] . '"></i>' . e($cb['naam']) . '</span>';
    $leg .= '<span><i style="background:#8a8f96"></i>meerdere aanbieders op dezelfde prijs (getal = aantal)</span></div>';
    return '<div class="card" style="margin-bottom:18px"><div class="gr-kop"><h2>De markt in één beeld</h2><span class="muted" style="font-size:12.5px">alle prijzen incl. btw</span></div>'
        . '<p class="gr-uitleg">Per variant: waar staat elke aanbieder ten opzichte van de mediaan (de rode lijn in het midden). De balk toont hoe ver ' . wn() . ' erboven (rood) of eronder (groen) zit. Links het aantal aanbieders (⚠ = minder dan ' . drempel('min_aanbieders') . ') en de marge van ' . wn() . ' bij de huidige prijs. Namen staan altijd onder de lijn, van links naar rechts; bij drukte een regel lager met een lijntje naar het bolletje. Wijs een bolletje aan voor details, klik een regel voor het product.</p>'
        . $leg . '<div style="overflow-x:auto"><div style="min-width:900px">' . $s . '</div></div></div>';
}

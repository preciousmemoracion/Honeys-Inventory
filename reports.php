<?php
require_once "auth.php";
require_login();

require_once "helpers.php";
require_once "product_image.php";

date_default_timezone_set('Asia/Manila');

[$from, $to, $preset, $days] = report_range();
$days  = max(1, (int)$days);
$today = date('Y-m-d');

// Use "sale_date >= start AND sale_date < day after end" instead of DATE(sale_date) BETWEEN,
// so MySQL can use an index on sale_date instead of scanning the whole table.
$toNext = date('Y-m-d', strtotime($to . ' +1 day'));

$error     = null;
$stats     = ['low' => 0];   // read by nav.php for the low-stock badge
$tot       = ['n' => 0, 'total' => 0, 'units' => 0];
$prevTotal = 0.0;
$daily = $top = $cats = $catRows = [];

try {
    $stats['low'] = (int)db_all($conn, "SELECT COUNT(*) AS c FROM products WHERE quantity <= low_stock_level")[0]['c'];

    $tot = db_all($conn,
        "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total,
                (SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si
                   JOIN sales s2 ON s2.id = si.sale_id
                  WHERE s2.sale_date >= ? AND s2.sale_date < ?) AS units
         FROM sales WHERE sale_date >= ? AND sale_date < ?",
        'ssss', [$from, $toNext, $from, $toNext]
    )[0];

    // The period right before this one, same length, for comparison
    $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
    $prevTotal = (float)db_all($conn,
        "SELECT COALESCE(SUM(total_amount), 0) AS t FROM sales WHERE sale_date >= ? AND sale_date < ?",
        'ss', [$prevFrom, $from]
    )[0]['t'];

    $byDay = [];
    foreach (db_all($conn,
        "SELECT DATE(sale_date) AS d, SUM(total_amount) AS t, COUNT(*) AS n
         FROM sales WHERE sale_date >= ? AND sale_date < ? GROUP BY DATE(sale_date)",
        'ss', [$from, $toNext]
    ) as $r) {
        $byDay[$r['d']] = $r;
    }
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($from . " +$i days"));
        $daily[] = ['date' => $d, 'total' => (float)($byDay[$d]['t'] ?? 0), 'count' => (int)($byDay[$d]['n'] ?? 0)];
    }

    // Best sellers, with the product photo (MAX picks one value per product; blank if the product was deleted)
    $top = db_all($conn,
        "SELECT si.product_name, MAX(p.image) AS image, SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE s.sale_date >= ? AND s.sale_date < ?
         GROUP BY si.product_name ORDER BY revenue DESC, qty DESC LIMIT 10",
        'ss', [$from, $toNext]
    );

    // One query for categories: per category and product, best first.
    // The category totals below are summed from these rows, so no second query is needed.
    $catRows = db_all($conn,
        "SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'Uncategorized') AS cat_name,
                si.product_name, MAX(p.image) AS image,
                SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE s.sale_date >= ? AND s.sale_date < ?
         GROUP BY cat_name, si.product_name ORDER BY revenue DESC",
        'ss', [$from, $toNext]
    );
} catch (Throwable $ex) {
    error_log('Reports error: ' . $ex->getMessage());
    $error = "The report couldn't load. Check the database connection and try again.";
    $tot = ['n' => 0, 'total' => 0, 'units' => 0];
    $prevTotal = 0.0;
    $daily = $top = $catRows = [];
}

// Build category totals from the product rows
$catTotals = [];
foreach ($catRows as $r) {
    $k = $r['cat_name'];
    $catTotals[$k] ??= ['cat_name' => $k, 'qty' => 0.0, 'revenue' => 0.0];
    $catTotals[$k]['qty']     += (float)$r['qty'];
    $catTotals[$k]['revenue'] += (float)$r['revenue'];
}
$cats = array_values($catTotals);
usort($cats, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

$count   = (int)$tot['n'];
$total   = (float)$tot['total'];
$average = $count > 0 ? $total / $count : 0;

$dailyTotals = array_column($daily, 'total');
$dailyMax    = max(array_merge([1], $dailyTotals));
$dense       = count($daily) > 14;
$labelEvery  = count($daily) > 60 ? 14 : (count($daily) > 31 ? 7 : (count($daily) > 14 ? 3 : 1));
$best        = null;
foreach ($daily as $d) {
    if ($d['total'] > 0 && ($best === null || $d['total'] > $best['total'])) { $best = $d; }
}
$topMax = $top ? max(array_merge([1], array_map('floatval', array_column($top, 'revenue')))) : 1;

// Up to 3 photos per category: its best-selling products that have a photo
$catPhotos = [];
foreach ($catRows as $r) {
    if (product_image_url($r['image'] ?? null) === '') { continue; }
    if (count($catPhotos[$r['cat_name']] ?? []) < 3) { $catPhotos[$r['cat_name']][] = $r; }
}
$catMax = $cats ? max(array_merge([1], array_column($cats, 'revenue'))) : 1;

$compare = '';
if ($error === null) {
    if ($prevTotal > 0) {
        $change  = ($total - $prevTotal) / $prevTotal * 100;
        $compare = ($change >= 0 ? 'Up ' : 'Down ') . number_format(abs($change), 1) . '% from the previous ' . $days . ($days === 1 ? ' day' : ' days');
    } elseif ($total > 0) {
        $compare = 'No sales in the previous period to compare with';
    }
}

$presets = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'last_month' => 'Last month'];
$rangeLabel = date('M j, Y', strtotime($from)) . ($from !== $to ? ' to ' . date('M j, Y', strtotime($to)) : '');
$exportQs = 'from=' . urlencode($from) . '&amp;to=' . urlencode($to);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports | Honey's Dressed Chicken and Frozen Food</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wdth,wght@12..96,75..100,600..800&family=Figtree:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="theme.css">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <style>
        /* Two columns on wide screens, stacked on narrow ones (was an inline style that blocked stacking) */
        .split.split-2 { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
        @media (max-width: 860px) { .split.split-2 { grid-template-columns: minmax(0, 1fr); } }

        /* Product photos in Best sellers */
        .rk-name { display: flex; align-items: center; gap: .85rem; min-width: 0; font-weight: 600; }
        .rk-name > span:last-child { min-width: 0; overflow-wrap: anywhere; }
        .thumb { overflow: hidden; }
        .rk-name .thumb { flex: none; width: 4rem; height: 4rem; border-radius: 12px; font-size: 1.4rem; }
        .rk-name .thumb:not(.has-img) { display: grid; place-items: center; font-weight: 700; color: #fff; background: hsl(var(--h) 50% 32%); }
        .thumb.has-img { padding: 0; background: #141A4A; box-shadow: 0 0 0 1px rgba(190, 182, 255, .3), 0 8px 20px -10px rgba(0, 0, 0, .7); }
        .thumb img { display: block; width: 100%; height: 100%; object-fit: cover; border-radius: inherit; transition: transform .25s ease; }
        .thumb a.zoom { display: block; width: 100%; height: 100%; border-radius: inherit; cursor: zoom-in; }
        .thumb a.zoom:hover img { transform: scale(1.08); }
        .thumb a.zoom:focus-visible { outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 2px; }
        .cat-stack { display: flex; flex: none; }
        .cat-stack .thumb { width: 3.5rem; height: 3.5rem; border-radius: 50%; }
        .cat-stack .thumb + .thumb { margin-left: -1rem; }
        .cat-stack .thumb.has-img { box-shadow: 0 0 0 2px #0E1236; }
        @media (prefers-reduced-motion: reduce) { .thumb img { transition: none; } }
        @media print { .thumb { box-shadow: none; } .thumb a.zoom { pointer-events: none; } }
        dialog.viewer { width: min(820px, calc(100% - 2rem)); padding: 0; border: 1px solid var(--line-strong, #4a4f8a); border-radius: 18px; overflow: hidden; background: #0E1236; color: var(--text, #fff); }
        dialog.viewer::backdrop { background: rgba(4, 6, 24, .78); }
        dialog.viewer img { display: block; width: 100%; max-height: 78dvh; object-fit: contain; background: #fff; }
        dialog.viewer p { margin: 0; padding: .85rem 1.1rem; font-weight: 600; }
        dialog.viewer button { position: absolute; top: .6rem; right: .6rem; width: 2.4rem; height: 2.4rem; border: 0; border-radius: 50%; background: rgba(10, 14, 42, .85); color: #fff; font-size: 1.3rem; cursor: pointer; }
        dialog.viewer button:focus-visible { outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 2px; }
    </style>
</head>
<body>

<?php include "nav.php"; ?>

<main class="content">
<div class="dash">
    <header class="dash-head">
        <div>
            <h1>Reports</h1>
            <p class="dash-shop">How sales are doing, <?= e($rangeLabel) ?>.</p>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <button class="btn" type="button" onclick="window.print()">Print report</button>
            <a class="btn" href="export.php?type=items&amp;<?= $exportQs ?>">Export items CSV</a>
            <a class="btn" href="export.php?type=sales&amp;<?= $exportQs ?>">Export sales CSV</a>
        </nav>
    </header>

    <div class="print-only">
        <h1 style="margin: 0 0 .25rem">Sales report</h1>
        <p style="margin: 0 0 1rem">Honey's Dressed Chicken and Frozen Food, <?= e($rangeLabel) ?></p>
    </div>

    <?php if ($error): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form class="filters no-print" method="GET" action="reports.php" aria-label="Report period">
        <div class="seg" role="group" aria-label="Quick periods">
            <?php foreach ($presets as $key => $label): ?>
                <a href="reports.php?range=<?= e($key) ?>"<?= $preset === $key ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= e($today) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= e($today) ?>">
        </div>
        <button class="btn primary" type="submit">Apply dates</button>
    </form>

    <section class="coldroom metrics four" aria-label="Summary">
        <div>
            <p class="cr-label">Sales</p>
            <p class="cr-number"><?= peso($total) ?></p>
            <?php if ($compare): ?><p class="cr-sub"><?= e($compare) ?></p><?php endif; ?>
        </div>
        <div>
            <p class="cr-label">Receipts</p>
            <p class="cr-number plain"><?= number_format($count) ?></p>
        </div>
        <div>
            <p class="cr-label">Average sale</p>
            <p class="cr-number plain"><?= peso($average) ?></p>
        </div>
        <div>
            <p class="cr-label">Units sold</p>
            <p class="cr-number plain"><?= number_format((float)$tot['units']) ?></p>
        </div>
    </section>

    <section class="panel" aria-labelledby="daily-title">
        <div class="panel-head">
            <div>
                <h2 id="daily-title">Sales by day</h2>
                <?php if ($best): ?>
                    <p class="panel-note">Best day: <?= e(date('l, M j', strtotime($best['date']))) ?> with <?= peso($best['total']) ?>.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($total > 0): ?>
        <div class="bars <?= $dense ? 'dense' : '' ?>" role="img"
             aria-label="Daily sales from <?= e($rangeLabel) ?>. Total <?= peso($total) ?>.<?= $best ? ' Best day ' . e(date('M j', strtotime($best['date']))) . ' with ' . peso($best['total']) . '.' : '' ?>">
            <?php foreach ($daily as $i => $d):
                $isToday = $d['date'] === $today;
                $ts = strtotime($d['date']);
            ?>
            <div class="col <?= $isToday ? 'today' : '' ?> <?= $d['total'] <= 0 ? 'empty' : '' ?>"
                 title="<?= e(date('D, M j', $ts)) ?>: <?= peso($d['total']) ?> from <?= $d['count'] ?> <?= $d['count'] === 1 ? 'receipt' : 'receipts' ?>">
                <b><?= $d['total'] > 0 ? e(peso_short($d['total'])) : '' ?></b>
                <span class="track"><i style="--h: <?= round($d['total'] / $dailyMax * 100, 1) ?>"></i></span>
                <span class="day"><?= ($i % $labelEvery === 0) ? e(date($dense ? 'M j' : 'D', $ts)) : '' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif (!$error): ?>
            <p class="panel-note">No sales in this period. Try a longer range, or <a href="sales.php">start a new sale</a>.</p>
        <?php endif; ?>
    </section>

    <div class="split split-2">
        <section class="panel" aria-labelledby="top-title">
            <div class="panel-head"><h2 id="top-title">Best sellers</h2></div>
            <?php if ($top): ?>
            <ol class="rank">
                <?php foreach ($top as $t): ?>
                <li>
                    <div class="row">
                        <span class="rk-name"><?= product_thumb($t) ?><span><?= e($t['product_name']) ?></span></span>
                        <b><?= peso($t['revenue']) ?></b>
                    </div>
                    <small><?= number_format((float)$t['qty']) ?> sold<?= $total > 0 ? ', ' . round((float)$t['revenue'] / $total * 100) . '% of sales' : '' ?></small>
                    <div class="meter"><i style="--w: <?= round((float)$t['revenue'] / $topMax * 100) ?>%"></i></div>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php elseif (!$error): ?>
                <p class="panel-note">No products sold in this period.</p>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="cat-title">
            <div class="panel-head"><h2 id="cat-title">Sales by category</h2></div>
            <?php if ($cats): ?>
            <ol class="rank">
                <?php foreach ($cats as $c): ?>
                <li>
                    <?php $ph = $catPhotos[$c['cat_name']] ?? []; ?>
                    <div class="row">
                        <span class="rk-name">
                            <?php if ($ph): ?>
                                <span class="cat-stack" title="<?= e(implode(', ', array_column($ph, 'product_name'))) ?>"><?php foreach ($ph as $pp): ?><?= product_thumb($pp, false) ?><?php endforeach; ?></span>
                            <?php else: ?>
                                <?= product_thumb(['product_name' => $c['cat_name']], false) ?>
                            <?php endif; ?>
                            <span><?= e($c['cat_name']) ?></span>
                        </span>
                        <b><?= peso($c['revenue']) ?></b>
                    </div>
                    <small><?= number_format((float)$c['qty']) ?> sold<?= $total > 0 ? ', ' . round((float)$c['revenue'] / $total * 100) . '% of sales' : '' ?></small>
                    <div class="meter"><i style="--w: <?= round((float)$c['revenue'] / $catMax * 100) ?>%"></i></div>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php elseif (!$error): ?>
                <p class="panel-note">No category data for this period.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
</main>

<dialog class="viewer" id="viewer" aria-label="Product photo">
    <button type="button" id="viewerClose" aria-label="Close photo">&times;</button>
    <img id="viewerImg" alt="">
    <p id="viewerCap"></p>
</dialog>

<script>
(function () {
    /* Click a product photo to see it larger */
    var viewer = document.getElementById('viewer');
    if (!viewer || !viewer.showModal) return;
    var img = document.getElementById('viewerImg');
    var cap = document.getElementById('viewerCap');
    var lastLink = null;

    document.querySelectorAll('a.zoom').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            lastLink = a;
            img.src = a.href;
            img.alt = a.dataset.zoom || '';
            cap.textContent = a.dataset.zoom || '';
            viewer.showModal();
        });
    });
    document.getElementById('viewerClose').addEventListener('click', function () { viewer.close(); });
    viewer.addEventListener('click', function (e) { if (e.target === viewer) viewer.close(); });
    /* Runs for the close button, backdrop click and the Esc key alike */
    viewer.addEventListener('close', function () {
        img.removeAttribute('src');
        if (lastLink) lastLink.focus();
    });
})();
</script>
<script src="app.js" defer></script>
</body>
</html>
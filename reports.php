<?php
require_once "auth.php";
require_login();

require_once "helpers.php";

date_default_timezone_set('Asia/Manila');

[$from, $to, $preset, $days] = report_range();
$today = date('Y-m-d');

$error = null;
$stats = ['low' => 0];
$tot   = ['n' => 0, 'total' => 0, 'units' => 0];
$prevTotal = 0.0;
$daily = $top = $cats = [];

try {
    $stats['low'] = (int)db_all($conn, "SELECT COUNT(*) AS c FROM products WHERE quantity <= low_stock_level")[0]['c'];

    $tot = db_all($conn,
        "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total,
                (SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si
                   JOIN sales s2 ON s2.id = si.sale_id
                  WHERE DATE(s2.sale_date) BETWEEN ? AND ?) AS units
         FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?",
        'ssss', [$from, $to, $from, $to]
    )[0];

    // The period right before this one, same length, for comparison
    $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
    $prevTotal = (float)db_all($conn,
        "SELECT COALESCE(SUM(total_amount), 0) AS t FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?",
        'ss', [$prevFrom, $prevTo]
    )[0]['t'];

    $byDay = [];
    foreach (db_all($conn,
        "SELECT DATE(sale_date) AS d, SUM(total_amount) AS t, COUNT(*) AS n
         FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? GROUP BY DATE(sale_date)",
        'ss', [$from, $to]
    ) as $r) {
        $byDay[$r['d']] = $r;
    }
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($from . " +$i days"));
        $daily[] = ['date' => $d, 'total' => (float)($byDay[$d]['t'] ?? 0), 'count' => (int)($byDay[$d]['n'] ?? 0)];
    }

    $top = db_all($conn,
        "SELECT si.product_name, SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE DATE(s.sale_date) BETWEEN ? AND ?
         GROUP BY si.product_name ORDER BY revenue DESC LIMIT 10",
        'ss', [$from, $to]
    );

    $cats = db_all($conn,
        "SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'Uncategorized') AS cat_name,
                SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE DATE(s.sale_date) BETWEEN ? AND ?
         GROUP BY cat_name ORDER BY revenue DESC",
        'ss', [$from, $to]
    );
} catch (Throwable $ex) {
    error_log('Reports error: ' . $ex->getMessage());
    $error = "The report couldn't load. Check the database connection and try again.";
    $tot = ['n' => 0, 'total' => 0, 'units' => 0];
}

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
$catMax = $cats ? max(array_merge([1], array_map('floatval', array_column($cats, 'revenue')))) : 1;

if ($prevTotal > 0) {
    $change = ($total - $prevTotal) / $prevTotal * 100;
    $compare = ($change >= 0 ? 'Up ' : 'Down ') . number_format(abs($change), 1) . '% from the previous ' . $days . ($days === 1 ? ' day' : ' days');
} else {
    $compare = $total > 0 ? 'No sales in the previous period to compare with' : '';
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
             aria-label="Daily sales from <?= e($rangeLabel) ?>. Total <?= peso($total) ?>.">
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
        <?php else: ?>
            <p class="panel-note">No sales in this period. Try a longer range, or <a href="sales.php">start a new sale</a>.</p>
        <?php endif; ?>
    </section>

    <div class="split" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)">
        <section class="panel" aria-labelledby="top-title">
            <div class="panel-head"><h2 id="top-title">Best sellers</h2></div>
            <?php if ($top): ?>
            <ol class="rank">
                <?php foreach ($top as $t): ?>
                <li>
                    <div class="row"><span><?= e($t['product_name']) ?></span><b><?= peso($t['revenue']) ?></b></div>
                    <small><?= number_format((float)$t['qty']) ?> sold<?= $total > 0 ? ', ' . round((float)$t['revenue'] / $total * 100) . '% of sales' : '' ?></small>
                    <div class="meter"><i style="--w: <?= round((float)$t['revenue'] / $topMax * 100) ?>%"></i></div>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php else: ?>
                <p class="panel-note">No products sold in this period.</p>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="cat-title">
            <div class="panel-head"><h2 id="cat-title">Sales by category</h2></div>
            <?php if ($cats): ?>
            <ol class="rank">
                <?php foreach ($cats as $c): ?>
                <li>
                    <div class="row"><span><?= e($c['cat_name']) ?></span><b><?= peso($c['revenue']) ?></b></div>
                    <small><?= number_format((float)$c['qty']) ?> sold<?= $total > 0 ? ', ' . round((float)$c['revenue'] / $total * 100) . '% of sales' : '' ?></small>
                    <div class="meter"><i style="--w: <?= round((float)$c['revenue'] / $catMax * 100) ?>%"></i></div>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php else: ?>
                <p class="panel-note">No category data for this period.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
</main>
<script src="app.js" defer></script>
</body>
</html>
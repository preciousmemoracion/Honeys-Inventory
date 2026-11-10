<?php
require_once "auth.php";
require_login();

require_once "db.php";
require_once "product_image.php";

date_default_timezone_set('Asia/Manila');

/* Fallbacks in case db.php doesn't define these helpers */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('peso')) {
    function peso($amount): string {
        return '₱' . number_format((float)$amount, 2);
    }
}

/* Product picture (click to enlarge), or a colored tile with the first letter when there is no photo */
if (!function_exists('product_thumb')) {
    function product_thumb(array $p): string {
        $name = (string)$p['product_name'];
        $hue  = crc32(strtolower($name)) % 360;
        $url  = product_image_url($p['image'] ?? null);
        if ($url === '') {
            $ltr = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));
            return '<span class="thumb" style="--h: ' . $hue . '" aria-hidden="true">' . e($ltr) . '</span>';
        }
        return '<span class="thumb has-img" style="--h: ' . $hue . '"><a class="zoom" href="' . e($url) . '" data-zoom="' . e($name)
             . '" aria-label="View photo of ' . e($name) . '"><img src="' . e($url) . '" alt="" loading="lazy" width="88" height="88"></a></span>';
    }
}

/* Small line icons for the cards and lists */
if (!function_exists('dash_icon')) {
    function dash_icon(string $name): string {
        $paths = [
            'box'     => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
            'layers'  => '<path d="M12 3 3 8l9 5 9-5z"/><path d="m3 13 9 5 9-5"/>',
            'alert'   => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.01"/>',
            'cash'    => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M7 9.5v.01M17 14.5v.01"/>',
            'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . ($paths[$name] ?? '') . '</svg>';
    }
}

/* Run a query and return all rows, or fail with a clear message */
function fetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$error     = null;
$todayStr  = date('Y-m-d');
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');
$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

try {
    // One query for all three summary cards
    $stats = fetchAll($conn,
        "SELECT COUNT(*) AS products,
                COALESCE(SUM(quantity), 0) AS stock,
                COALESCE(SUM(quantity <= low_stock_level), 0) AS low,
                COALESCE(SUM(quantity = 0), 0) AS out_count
         FROM products"
    )[0];

    // Today's sales. Range comparisons (not DATE(col)) so an index on sale_date can be used.
    $today = fetchAll($conn,
        "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
         FROM sales WHERE sale_date >= ? AND sale_date < ?",
        'ss', [$todayStr, $tomorrow]
    )[0];

    // Yesterday, for the "vs yesterday" note on the sales card
    $yesterdayTotal = (float)fetchAll($conn,
        "SELECT COALESCE(SUM(total_amount), 0) AS t FROM sales WHERE sale_date >= ? AND sale_date < ?",
        'ss', [$yesterday, $todayStr]
    )[0]['t'];

    // Products needing restock, most urgent first
    $restock = fetchAll($conn,
        "SELECT product_name, image, quantity, low_stock_level, unit
         FROM products
         WHERE quantity <= low_stock_level
         ORDER BY quantity ASC, product_name
         LIMIT 5"
    );

    // Inventory: low-stock items float to the top
    $products = fetchAll($conn,
        "SELECT product_name, image, category, quantity, low_stock_level, unit, price
         FROM products
         ORDER BY (quantity <= low_stock_level) DESC, product_name
         LIMIT 10"
    );

    $sales = fetchAll($conn,
        "SELECT id, sale_date, total_amount
         FROM sales ORDER BY id DESC LIMIT 5"
    );

    // Daily sales for the last 7 days, with empty days filled in as zero
    $byDay = [];
    foreach (fetchAll($conn,
        "SELECT DATE(sale_date) AS d, SUM(total_amount) AS t, COUNT(*) AS n
         FROM sales WHERE sale_date >= ? AND sale_date < ? GROUP BY DATE(sale_date)",
        'ss', [$weekStart, $tomorrow]
    ) as $r) {
        $byDay[$r['d']] = $r;
    }
    $week = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $week[] = ['date' => $d,
                   'total' => (float)($byDay[$d]['t'] ?? 0),
                   'count' => (int)($byDay[$d]['n'] ?? 0)];
    }

    // Top moving products this month, by units sold
    $top = fetchAll($conn,
        "SELECT si.product_name, SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE s.sale_date >= ?
         GROUP BY si.product_name ORDER BY qty DESC LIMIT 5",
        's', [$monthStart]
    );
} catch (Throwable $ex) {
    error_log('Dashboard error: ' . $ex->getMessage());
    $error = "The dashboard couldn't load its data. Check the database connection and try again.";
    $stats = ['products' => 0, 'stock' => 0, 'low' => 0, 'out_count' => 0];
    $today = ['count' => 0, 'total' => 0];
    $yesterdayTotal = 0.0;
    $restock = $products = $sales = $week = $top = [];
}

/* "Up 12% vs yesterday" for the sales card */
$trend = null;
if (!$error && $yesterdayTotal > 0) {
    $chg   = ((float)$today['total'] - $yesterdayTotal) / $yesterdayTotal * 100;
    $trend = ['up' => $chg >= 0, 'text' => ($chg >= 0 ? 'Up ' : 'Down ') . number_format(abs($chg), 0) . '% vs yesterday'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Honey's Dressed Chicken and Frozen Food</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wdth,wght@12..96,75..100,600..800&family=Figtree:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="theme.css">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <style>
        /* ---------- Product photos ---------- */
        .pname { gap: .9rem; }
        .thumb { overflow: hidden; }
        .pname .thumb { flex: none; width: 3.5rem; height: 3.5rem; border-radius: 12px; font-size: 1.4rem; }
        .pname .thumb:not(.has-img) { display: grid; place-items: center; font-weight: 700; color: #fff; background: hsl(var(--h) 50% 32%); }
        .thumb.has-img { padding: 0; background: #141A4A; box-shadow: 0 0 0 1px rgba(190, 182, 255, .3), 0 8px 20px -10px rgba(0, 0, 0, .7); }
        .thumb img { display: block; width: 100%; height: 100%; object-fit: cover; border-radius: inherit; transition: transform .25s ease; }
        .thumb a.zoom { display: block; width: 100%; height: 100%; border-radius: inherit; cursor: zoom-in; }
        .thumb a.zoom:hover img { transform: scale(1.08); }
        .thumb a.zoom:focus-visible { outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 2px; }
        .pname > span:last-child { font-weight: 600; }
        .rs-name { display: flex; align-items: center; gap: .8rem; min-width: 0; }
        .rs-name strong { min-width: 0; overflow-wrap: anywhere; }
        .restock-list .thumb { flex: none; width: 3rem; height: 3rem; border-radius: 12px; font-size: 1.15rem; }
        .rs-name .thumb:not(.has-img) { display: grid; place-items: center; font-weight: 700; color: #fff; background: hsl(var(--h) 50% 32%); }

        /* ---------- Entrance ---------- */
        @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 170, 90, .6); } 70% { box-shadow: 0 0 0 .55rem rgba(255, 170, 90, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 170, 90, 0); } }
        .dash > .cards      { animation: rise .45s ease both .05s; }
        .dash > .main-grid  { animation: rise .45s ease both .15s; }
        .dash > .split      { animation: rise .45s ease both .25s; }

        /* ---------- Greeting ---------- */
        .dash-title h1 { letter-spacing: -.01em; }
        .dash-title .dash-shop { opacity: .8; }

        /* ---------- Summary cards ---------- */
        .cards .card.kpi { position: relative; overflow: hidden; text-decoration: none;
            background-image: linear-gradient(160deg, rgba(190, 182, 255, .10), rgba(190, 182, 255, 0) 55%);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
        .cards .card.kpi:hover { transform: translateY(-3px); box-shadow: 0 20px 36px -22px rgba(0, 0, 0, .85); border-color: rgba(190, 182, 255, .45); }
        .cards .card.kpi:focus-visible { outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 3px; }
        .kpi h3 { padding-right: 3.2rem; }
        .kpi .kpi-icon { position: absolute; top: 1rem; right: 1rem; display: grid; place-items: center; width: 2.6rem; height: 2.6rem; border-radius: 13px;
            background: rgba(103, 232, 249, .12); color: var(--cyan-soft, #67e8f9); }
        .kpi .kpi-icon svg { width: 1.3rem; height: 1.3rem; }
        .kpi.alert-card .kpi-icon { background: rgba(255, 170, 90, .16); color: #ffb870; }
        .kpi .number { font-variant-numeric: tabular-nums; }
        .kpi .trend { font-weight: 600; }
        .kpi .trend.up { color: #6ee7a8; }
        .kpi .trend.down { color: #ff9b9b; }

        /* ---------- Panels and tables ---------- */
        .dash .panel-head h2 { letter-spacing: -.005em; }
        .dash table tbody tr { transition: background-color .15s ease; }
        .dash table tbody tr:not(.empty-row):hover { background: rgba(190, 182, 255, .07); }
        .dash .more { margin-top: 1rem; text-align: center; }

        /* ---------- Low stock alerts ---------- */
        .restock .panel-head h2 { display: flex; align-items: center; gap: .6rem; }
        .restock .panel-head h2::before { content: ""; flex: none; width: .65rem; height: .65rem; border-radius: 50%; background: #ffaa5a; animation: pulse 1.8s ease-out infinite; }
        .restock-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .35rem; }
        .restock-list li { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .5rem .6rem; border-radius: 14px; transition: background-color .15s ease; }
        .restock-list li:hover { background: rgba(255, 170, 90, .08); }
        .rs-qty { flex: none; padding: .2rem .7rem; border-radius: 999px; font-size: .85rem; font-weight: 700; white-space: nowrap;
            background: rgba(255, 170, 90, .16); color: #ffc58f; }
        .rs-qty.out { background: rgba(255, 110, 110, .16); color: #ffabab; }

        /* ---------- Recent sales ---------- */
        .sale-row { display: flex; align-items: center; gap: .75rem; min-width: 0; }
        .sale-ic { flex: none; display: grid; place-items: center; width: 2.25rem; height: 2.25rem; border-radius: 10px;
            background: rgba(103, 232, 249, .12); color: var(--cyan-soft, #67e8f9); }
        .sale-ic svg { width: 1.1rem; height: 1.1rem; }
        .sale-row > span:last-child a { font-weight: 700; }
        .mini li .amt { font-weight: 700; font-variant-numeric: tabular-nums; }

        @media (max-width: 560px) { .pname .thumb { width: 3rem; height: 3rem; font-size: 1.2rem; } }
        @media (prefers-reduced-motion: reduce) {
            .dash > .cards, .dash > .main-grid, .dash > .split { animation: none; }
            .restock .panel-head h2::before { animation: none; }
            .thumb img, .cards .card.kpi, .dash table tbody tr, .restock-list li { transition: none; }
            .cards .card.kpi:hover { transform: none; }
        }
        @media print { .dash > * { animation: none !important; } .cards .card.kpi:hover { transform: none; } }

        /* ---------- Photo viewer ---------- */
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
    <?php
        $lowNow     = (int)$stats['low'];
        $headStatus = $error ? null : ($lowNow > 0 ? 'low' : 'ok');
    ?>
    <header class="dash-head">
        <div class="dash-title">
            <h1><?= e($greeting) ?></h1>
            <p class="dash-shop">Honey's Dressed Chicken and Frozen Food</p>
            <?php if ($headStatus): ?>
            <p class="dash-status <?= $headStatus ?>">
                <?= e(date('l, F j, Y')) ?>.
                <?php if ($headStatus === 'low'): ?>
                    <a href="#restock"><?= $lowNow ?>
                    <?= $lowNow === 1 ? 'product needs' : 'products need' ?> restocking</a>.
                <?php else: ?>
                    All stock levels are healthy.
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <a class="btn primary" href="sales.php">New sale</a>
            <a class="btn" href="products.php">Manage products</a>
            <a class="btn" href="stock_in.php">Record delivery</a>
        </nav>
    </header>

    <?php if ($error): ?>
        <div class="alert" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php
        $nProducts  = (int)$stats['products'];
        $nOut       = (int)($stats['out_count'] ?? 0);
        $nReceipts  = (int)$today['count'];
        $weekTotals = array_column($week, 'total');
        $weekMax    = max(array_merge([1], $weekTotals));
        $weekSum    = array_sum($weekTotals);
        $topMax     = $top ? max(array_merge([1], array_map('floatval', array_column($top, 'qty')))) : 1;
    ?>

    <div class="cards">
        <a class="card kpi" href="products.php">
            <span class="kpi-icon"><?= dash_icon('box') ?></span>
            <h3>Total products</h3>
            <p class="number"><?= number_format($nProducts) ?></p>
        </a>

        <a class="card kpi" href="products.php">
            <span class="kpi-icon"><?= dash_icon('layers') ?></span>
            <h3>Units in stock</h3>
            <p class="number"><?= number_format((int)$stats['stock']) ?></p>
        </a>

        <a class="card kpi <?= $lowNow > 0 ? 'alert-card' : '' ?>" href="products.php?status=low">
            <span class="kpi-icon"><?= dash_icon('alert') ?></span>
            <h3>Low-stock items <?php if ($lowNow > 0): ?><span class="count"><?= $lowNow ?></span><?php endif; ?></h3>
            <p class="number <?= $lowNow > 0 ? 'warn' : '' ?>"><?= $lowNow ?></p>
            <?php if ($nOut > 0): ?><p class="sub"><?= $nOut ?> out of stock</p><?php endif; ?>
        </a>

        <a class="card kpi" href="history.php?from=<?= e($todayStr) ?>&amp;to=<?= e($todayStr) ?>">
            <span class="kpi-icon"><?= dash_icon('cash') ?></span>
            <h3>Sales today</h3>
            <p class="number"><?= peso($today['total']) ?></p>
            <p class="sub"><?= $nReceipts ?> <?= $nReceipts === 1 ? 'receipt' : 'receipts' ?></p>
            <?php if ($trend): ?><p class="sub trend <?= $trend['up'] ? 'up' : 'down' ?>"><?= $trend['up'] ? '&#9650;' : '&#9660;' ?> <?= e($trend['text']) ?></p><?php endif; ?>
        </a>
    </div>

    <div class="main-grid">
        <section class="panel" aria-labelledby="inv-title">
            <div class="panel-head">
                <h2 id="inv-title">Product list</h2>
                <a class="btn primary no-print" href="products.php#add-product">Add product</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Category</th>
                            <th scope="col">Stock level</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$products): ?>
                        <tr class="empty-row">
                            <td colspan="5" class="empty">
                                No products yet. <a href="products.php#add-product">Add your first product</a>.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($products as $p):
                        $qty   = (int)$p['quantity'];
                        $low   = (int)$p['low_stock_level'];
                        $scale = max($low * 3, $qty, 1);
                        $fill  = (int)round($qty / $scale * 100);
                        $mark  = (int)round($low / $scale * 100);
                        if ($qty === 0) {
                            $level = 'out';
                            $badgeClass = 'out';
                            $badgeText  = 'Out of stock';
                        } elseif ($qty <= $low) {
                            $level = 'low';
                            $badgeClass = 'low';
                            $badgeText  = 'Low stock';
                        } else {
                            $level = 'ok';
                            $badgeClass = 'available';
                            $badgeText  = 'In stock';
                        }
                    ?>
                        <tr class="<?= $level !== 'ok' ? 'is-low' : '' ?>">
                            <td>
                                <div class="pname">
                                    <?= product_thumb($p) ?>
                                    <span><?= e($p['product_name']) ?></span>
                                </div>
                            </td>
                            <td><?= e($p['category']) ?></td>
                            <td>
                                <div class="stock">
                                    <span><?= number_format($qty) ?> <?= e($p['unit']) ?></span>
                                    <span class="gauge <?= $level ?>" role="img"
                                          aria-label="<?= $qty ?> in stock, alert at <?= $low ?>">
                                        <i style="width: <?= $fill ?>%"></i><b style="left: <?= $mark ?>%"></b>
                                    </span>
                                </div>
                            </td>
                            <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                            <td class="num"><?= peso($qty * (float)$p['price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="more no-print"><a class="btn" href="products.php">View all products</a></p>
        </section>

        <div class="stack">
            <?php if ($restock): ?>
            <section class="panel restock" id="restock" aria-labelledby="restock-title">
                <div class="panel-head">
                    <h2 id="restock-title">Low stock alerts</h2>
                    <a class="btn no-print" href="products.php?status=low">View all</a>
                </div>
                <ul class="restock-list">
                    <?php foreach ($restock as $r): $out = (int)$r['quantity'] === 0; ?>
                    <li>
                        <span class="rs-name">
                            <?= product_thumb($r) ?>
                            <strong><?= e($r['product_name']) ?></strong>
                        </span>
                        <span class="rs-qty <?= $out ? 'out' : '' ?>">
                            <?= $out ? 'Out of stock' : number_format((int)$r['quantity']) . ' ' . e($r['unit']) . ' left' ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="actions no-print">
                    <a class="btn primary" href="stock_in.php">Record delivery</a>
                </div>
            </section>
            <?php else: ?>
            <section class="panel" id="restock" aria-labelledby="restock-title">
                <div class="panel-head"><h2 id="restock-title">Low stock alerts</h2></div>
                <p class="panel-note">All stocked up. Every product is above its alert level.</p>
            </section>
            <?php endif; ?>

            <section class="panel" aria-labelledby="top-title">
                <div class="panel-head">
                    <div>
                        <h2 id="top-title">Stock analytics</h2>
                        <p class="panel-note">Top moving products (units sold this month)</p>
                    </div>
                </div>
                <?php if ($top): ?>
                <div class="bars named" role="img"
                     aria-label="Units sold this month: <?= e(implode(', ', array_map(fn($t) => $t['product_name'] . ' ' . (int)$t['qty'], $top))) ?>">
                    <?php foreach ($top as $t): ?>
                    <div class="col" title="<?= e($t['product_name']) ?>: <?= number_format((float)$t['qty']) ?> sold">
                        <b><?= number_format((float)$t['qty']) ?></b>
                        <span class="track"><i style="--h: <?= round((float)$t['qty'] / $topMax * 100, 1) ?>"></i></span>
                        <span class="day"><?= e($t['product_name']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <p class="panel-note">No sales yet this month. <a href="sales.php">Start a new sale</a>.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="split">
        <section class="panel" aria-labelledby="week-title">
            <div class="panel-head">
                <div>
                    <h2 id="week-title">Sales this week</h2>
                    <p class="panel-note"><?= peso($weekSum) ?> over the last 7 days.</p>
                </div>
                <a class="no-print" href="reports.php">Full report</a>
            </div>
            <?php if ($week): ?>
            <div class="bars" role="img" aria-label="Daily sales for the last 7 days">
                <?php foreach ($week as $w):
                    $isToday = $w['date'] === $todayStr;
                ?>
                <div class="col <?= $isToday ? 'today' : '' ?> <?= $w['total'] <= 0 ? 'empty' : '' ?>"
                     title="<?= e(date('D, M j', strtotime($w['date']))) ?>: <?= peso($w['total']) ?>">
                    <b><?= $w['total'] > 0 ? e(peso_short($w['total'])) : '' ?></b>
                    <span class="track"><i style="--h: <?= round($w['total'] / $weekMax * 100, 1) ?>"></i></span>
                    <span class="day"><?= $isToday ? 'Today' : e(date('D', strtotime($w['date']))) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="recent-title">
            <div class="panel-head">
                <h2 id="recent-title">Recent sales</h2>
                <a class="no-print" href="history.php">View all</a>
            </div>
            <?php if ($sales): ?>
            <ul class="mini">
                <?php foreach ($sales as $s):
                    $ts = strtotime($s['sale_date']);
                ?>
                <li>
                    <span class="sale-row">
                        <span class="sale-ic"><?= dash_icon('receipt') ?></span>
                        <span>
                            <a href="history.php?receipt=<?= (int)$s['id'] ?>">SALE-<?= (int)$s['id'] ?></a>
                            <small><?= $ts ? e(date('M j, Y g:i A', $ts)) : e($s['sale_date']) ?></small>
                        </span>
                    </span>
                    <span class="amt"><?= peso($s['total_amount']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="panel-note">No sales recorded yet. <a href="sales.php">Start a new sale</a>.</p>
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
    viewer.addEventListener('close', function () {
        img.removeAttribute('src');
        if (lastLink) lastLink.focus();
    });
})();
</script>
<script src="app.js" defer></script>
</body>
</html>
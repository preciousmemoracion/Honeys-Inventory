<?php
require_once "auth.php";
require_login();

require_once "db.php";

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

$error = null;

try {
    // One query for all three summary cards
    $stats = fetchAll($conn,
        "SELECT COUNT(*) AS products,
                COALESCE(SUM(quantity), 0) AS stock,
                COALESCE(SUM(quantity <= low_stock_level), 0) AS low,
                COALESCE(SUM(quantity = 0), 0) AS out_count
         FROM products"
    )[0];

    // Today's sales (date comes from PHP so it follows the Manila timezone)
    $today = fetchAll($conn,
        "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
         FROM sales WHERE DATE(sale_date) = ?",
        's', [date('Y-m-d')]
    )[0];

    // Products needing restock, most urgent first
    $restock = fetchAll($conn,
        "SELECT product_name, quantity, low_stock_level, unit
         FROM products
         WHERE quantity <= low_stock_level
         ORDER BY quantity ASC, product_name
         LIMIT 5"
    );

    // Inventory: low-stock items float to the top
    $products = fetchAll($conn,
        "SELECT product_name, category, quantity, low_stock_level, unit, price
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
         FROM sales WHERE DATE(sale_date) >= ? GROUP BY DATE(sale_date)",
        's', [date('Y-m-d', strtotime('-6 days'))]
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
         WHERE DATE(s.sale_date) >= ?
         GROUP BY si.product_name ORDER BY qty DESC LIMIT 5",
        's', [date('Y-m-01')]
    );
} catch (Throwable $ex) {
    error_log('Dashboard error: ' . $ex->getMessage());
    $error = "The dashboard couldn't load its data. Check the database connection and try again.";
    $stats = ['products' => 0, 'stock' => 0, 'low' => 0];
    $today = ['count' => 0, 'total' => 0];
    $restock = $products = $sales = $week = $top = [];
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
            <h1>Dashboard overview</h1>
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
        $todayStr   = date('Y-m-d');
    ?>

    <div class="cards">
        <a class="card" href="products.php">
            <h3>Total products</h3>
            <p class="number"><?= number_format($nProducts) ?></p>
        </a>

        <a class="card" href="products.php">
            <h3>Units in stock</h3>
            <p class="number"><?= number_format((int)$stats['stock']) ?></p>
        </a>

        <a class="card <?= $lowNow > 0 ? 'alert-card' : '' ?>" href="products.php?status=low">
            <h3>Low-stock items <?php if ($lowNow > 0): ?><span class="count"><?= $lowNow ?></span><?php endif; ?></h3>
            <p class="number <?= $lowNow > 0 ? 'warn' : '' ?>"><?= $lowNow ?></p>
            <?php if ($nOut > 0): ?><p class="sub"><?= $nOut ?> out of stock</p><?php endif; ?>
        </a>

        <a class="card" href="history.php?from=<?= e($todayStr) ?>&amp;to=<?= e($todayStr) ?>">
            <h3>Sales today</h3>
            <p class="number"><?= peso($today['total']) ?></p>
            <p class="sub"><?= $nReceipts ?> <?= $nReceipts === 1 ? 'receipt' : 'receipts' ?></p>
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
                        <tr>
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
                        $hue = crc32(strtolower((string)$p['product_name'])) % 360;
                        $ltr = function_exists('mb_substr') ? mb_strtoupper(mb_substr((string)$p['product_name'], 0, 1)) : strtoupper(substr((string)$p['product_name'], 0, 1));
                    ?>
                        <tr class="<?= $level !== 'ok' ? 'is-low' : '' ?>">
                            <td>
                                <div class="pname">
                                    <span class="thumb" style="--h: <?= (int)$hue ?>" aria-hidden="true"><?= e($ltr) ?></span>
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
                    <?php foreach ($restock as $r): ?>
                    <li>
                        <strong><?= e($r['product_name']) ?></strong>
                        <span>
                            <?= (int)$r['quantity'] === 0
                                ? 'Out of stock'
                                : '(' . number_format((int)$r['quantity']) . ' ' . e($r['unit']) . ' left)' ?>
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
                    <span>
                        <a href="history.php?receipt=<?= (int)$s['id'] ?>">SALE-<?= (int)$s['id'] ?></a>
                        <small><?= $ts ? e(date('M j, Y g:i A', $ts)) : e($s['sale_date']) ?></small>
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

<script src="app.js" defer></script>
</body>
</html>
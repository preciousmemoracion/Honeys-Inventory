<?php
require_once "auth.php";
require_login();

require_once "helpers.php";

date_default_timezone_set('Asia/Manila');

$error    = null;
$rows     = [];
$products = [];
$sum      = ['rin' => 0, 'rout' => 0, 'n' => 0];
$stats    = ['low' => 0];

$type   = in_array($_GET['type'] ?? '', ['in', 'out'], true) ? $_GET['type'] : 'all';
$pid    = (int)filter_var($_GET['product'] ?? 0, FILTER_VALIDATE_INT);
$from   = valid_date((string)($_GET['from'] ?? ''));
$to     = valid_date((string)($_GET['to'] ?? ''));
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$today    = date('Y-m-d');
$dateCol  = null;
$filtered = ($type !== 'all' || $pid > 0 || $from !== '' || $to !== '');

try {
    $dateCol  = movement_date_column($conn);
    $products = db_all($conn, "SELECT id, product_name FROM products ORDER BY product_name");
    $stats['low'] = (int)db_all($conn, "SELECT COUNT(*) AS c FROM products WHERE quantity <= low_stock_level")[0]['c'];

    $where = [];
    $types = '';
    $params = [];
    if ($type === 'in')  { $where[] = "t.transaction_type LIKE '%in'"; }
    if ($type === 'out') { $where[] = "t.transaction_type LIKE '%out'"; }
    if ($pid > 0)        { $where[] = 't.product_id = ?'; $types .= 'i'; $params[] = $pid; }
    if ($dateCol) {
        if ($from !== '') { $where[] = "DATE(t.`$dateCol`) >= ?"; $types .= 's'; $params[] = $from; }
        if ($to !== '')   { $where[] = "DATE(t.`$dateCol`) <= ?"; $types .= 's'; $params[] = $to; }
    }
    $w    = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $when = $dateCol ? ", t.`$dateCol` AS moved_at" : '';

    $sum = db_all($conn,
        "SELECT COALESCE(SUM(CASE WHEN t.transaction_type LIKE '%in'  THEN t.quantity END), 0) AS rin,
                COALESCE(SUM(CASE WHEN t.transaction_type LIKE '%out' THEN t.quantity END), 0) AS rout,
                COUNT(*) AS n
         FROM stock_transactions t $w",
        $types, $params
    )[0];

    $rows = db_all($conn,
        "SELECT t.id, t.transaction_type, t.quantity, t.remarks, t.product_id, p.product_name$when
         FROM stock_transactions t LEFT JOIN products p ON p.id = t.product_id
         $w ORDER BY t.id DESC LIMIT 300",
        $types, $params
    );
} catch (Throwable $ex) {
    error_log('Stock log error: ' . $ex->getMessage());
    $error = "The stock log couldn't load. Check the database connection and try again.";
}

$net = (int)$sum['rin'] - (int)$sum['rout'];
$exportQuery = http_build_query(array_filter([
    'type' => 'movements', 'dir' => $type !== 'all' ? $type : '', 'product' => $pid ?: '', 'from' => $from, 'to' => $to,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock log | Honey's Dressed Chicken and Frozen Food</title>
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
    <header class="dash-head">
        <div>
            <h1>Stock log</h1>
            <p class="dash-shop">Every sale, delivery and adjustment, newest first.</p>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <a class="btn" href="export.php?<?= e($exportQuery) ?>">Export CSV</a>
            <a class="btn primary" href="stock_in.php">Record delivery</a>
        </nav>
    </header>

    <?php if ($error): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <section class="coldroom metrics" aria-label="Movement summary">
        <div>
            <p class="cr-label">Received or added</p>
            <p class="cr-number"><?= number_format((int)$sum['rin']) ?></p>
        </div>
        <div>
            <p class="cr-label">Sold or removed</p>
            <p class="cr-number plain"><?= number_format((int)$sum['rout']) ?></p>
        </div>
        <div>
            <p class="cr-label">Net change</p>
            <p class="cr-number plain"><?= ($net > 0 ? '+' : '') . number_format($net) ?></p>
        </div>
    </section>

    <section class="panel" aria-labelledby="log-title">
        <div class="panel-head">
            <h2 id="log-title"><?= $filtered ? 'Matching movements' : 'All movements' ?></h2>
        </div>

        <form class="filters no-print" method="GET" action="movements.php">
            <div class="field">
                <label for="type">Type</label>
                <select class="select" id="type" name="type">
                    <option value="all">Everything</option>
                    <option value="in"<?= $type === 'in' ? ' selected' : '' ?>>Stock in</option>
                    <option value="out"<?= $type === 'out' ? ' selected' : '' ?>>Stock out</option>
                </select>
            </div>
            <div class="field">
                <label for="product">Product</label>
                <select class="select" id="product" name="product">
                    <option value="0">All products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"<?= $pid === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($dateCol): ?>
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= e($today) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= e($today) ?>">
            </div>
            <?php endif; ?>
            <button class="btn primary" type="submit">Apply filter</button>
            <?php if ($filtered): ?><a class="btn" href="movements.php">Clear</a><?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php if ($dateCol): ?><th scope="col">When</th><?php endif; ?>
                        <th scope="col">Product</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="num">Quantity</th>
                        <th scope="col">Note</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= $dateCol ? 5 : 4 ?>" class="empty">
                            <?= $filtered
                                ? 'No movements match these filters. <a href="movements.php">Show everything</a>.'
                                : 'Nothing recorded yet. Sales, deliveries and adjustments will show up here.' ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $r):
                    $in = move_is_in($r['transaction_type']);
                    $ts = isset($r['moved_at']) ? strtotime((string)$r['moved_at']) : false;
                ?>
                    <tr>
                        <?php if ($dateCol): ?>
                            <td class="muted" style="font-weight: 400"><?= $ts ? e(date('M j, Y g:i A', $ts)) : '' ?></td>
                        <?php endif; ?>
                        <td class="keep"><?php if ($r['product_name'] !== null): ?>
                                <a href="edit_product.php?id=<?= (int)$r['product_id'] ?>" style="color: inherit"><?= e($r['product_name']) ?></a>
                            <?php else: ?>
                                <span style="color: var(--muted)">(deleted product)</span>
                            <?php endif; ?></td>
                        <td><span class="badge <?= $in ? 'in' : 'outt' ?>"><?= $in ? 'Stock in' : 'Stock out' ?></span></td>
                        <td class="num <?= $in ? 'qty-in' : 'qty-out' ?>"><?= $in ? '+' : '-' ?><?= number_format((int)$r['quantity']) ?></td>
                        <td class="muted" style="font-weight: 400"><?= move_note_html($r['remarks']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ((int)$sum['n'] > count($rows)): ?>
            <p class="note">Showing the latest <?= count($rows) ?> of <?= number_format((int)$sum['n']) ?> movements.
                <?= $dateCol ? 'Use the filters to narrow the list, or export everything to CSV.' : 'Export to CSV to get the full list.' ?></p>
        <?php endif; ?>
    </section>
</div>
</main>
<script src="app.js" defer></script>
</body>
</html>
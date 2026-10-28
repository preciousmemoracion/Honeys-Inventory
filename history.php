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

function validDate(string $d): string {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
}

$error    = null;
$receipt  = null;
$items    = [];
$sales    = [];
$summary  = ['n' => 0, 'total' => 0];
$stats    = ['low' => 0];
$notFound = false;

$from = validDate((string)($_GET['from'] ?? ''));
$to   = validDate((string)($_GET['to'] ?? ''));
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$today = date('Y-m-d');

try {
    $stats['low'] = (int)fetchAll($conn,
        "SELECT COUNT(*) AS c FROM products WHERE quantity <= low_stock_level"
    )[0]['c'];

    if (isset($_GET['receipt'])) {
        $id = filter_var($_GET['receipt'], FILTER_VALIDATE_INT);
        if ($id && $id > 0) {
            $receipt = fetchAll($conn, "SELECT * FROM sales WHERE id = ?", 'i', [$id])[0] ?? null;
            if ($receipt) {
                $items = fetchAll($conn,
                    "SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id", 'i', [$id]
                );
            }
        }
        if (!$receipt) {
            $notFound = true;
        }
    }

    if (!$receipt) {
        $where = [];
        $types = '';
        $params = [];
        if ($from !== '') { $where[] = 'DATE(s.sale_date) >= ?'; $types .= 's'; $params[] = $from; }
        if ($to !== '')   { $where[] = 'DATE(s.sale_date) <= ?'; $types .= 's'; $params[] = $to; }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $summary = fetchAll($conn,
            "SELECT COUNT(*) AS n, COALESCE(SUM(s.total_amount), 0) AS total FROM sales s $w",
            $types, $params
        )[0];

        $sales = fetchAll($conn,
            "SELECT s.id, s.sale_date, s.total_amount,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
             FROM sales s $w ORDER BY s.id DESC LIMIT 200",
            $types, $params
        );
    }
} catch (Throwable $ex) {
    error_log('History error: ' . $ex->getMessage());
    $error = "The sales history couldn't load. Check the database connection and try again.";
}

$count   = (int)$summary['n'];
$total   = (float)$summary['total'];
$average = $count > 0 ? $total / $count : 0;
$filtered = ($from !== '' || $to !== '');

if ($receipt) {
    $rts = strtotime($receipt['sale_date']);
    $receiptDate = $rts ? date('F j, Y g:i A', $rts) : $receipt['sale_date'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales History | Honey's Dressed Chicken and Frozen Food</title>
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
            <h1>Sales history</h1>
            <p class="dash-shop">
                <?= $receipt
                    ? 'Receipt SALE-' . (int)$receipt['id'] . ' from ' . e($receiptDate) . '.'
                    : 'Look up past sales and reprint receipts.' ?>
            </p>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <?php if ($receipt): ?>
                <button class="btn primary" type="button" onclick="window.print()">Print receipt</button>
                <a class="btn" href="history.php">Back to history</a>
            <?php else: ?>
                <a class="btn" href="export.php?type=sales&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>">Export CSV</a>
                <a class="btn primary" href="sales.php">New sale</a>
            <?php endif; ?>
        </nav>
    </header>

    <?php if ($error): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php elseif ($notFound): ?>
        <p class="alert" role="alert">Receipt not found. Pick one from the list below.</p>
    <?php endif; ?>

<?php if ($receipt): ?>

    <article id="receipt" class="receipt">
        <div class="receipt-head">
            <h2>Honey's Dressed Chicken</h2>
            <p>and Frozen Food</p>
            <p>Poblacion, Inabanga, Bohol</p>
        </div>

        <p class="receipt-title">Sales receipt</p>

        <dl class="receipt-meta">
            <div><dt>Receipt no.</dt><dd>SALE-<?= (int)$receipt['id'] ?></dd></div>
            <div><dt>Date</dt><dd><?= e($receiptDate) ?></dd></div>
        </dl>

        <table>
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Price</th>
                    <th scope="col">Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['product_name']) ?></td>
                    <td><?= (int)$item['quantity'] ?></td>
                    <td><?= peso($item['price']) ?></td>
                    <td><?= peso($item['subtotal']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="receipt-total">
            <span>Total</span>
            <span><?= peso($receipt['total_amount']) ?></span>
        </div>

        <p class="receipt-foot">Thank you for your purchase!</p>
        <p>System-generated record; not an official tax receipt.</p>
    </article>

<?php else: ?>

    <section class="coldroom metrics" aria-label="Sales summary">
        <div>
            <p class="cr-label"><?= $filtered ? 'Sales in range' : 'Total sales' ?></p>
            <p class="cr-number"><?= peso($total) ?></p>
        </div>
        <div>
            <p class="cr-label"><?= $filtered ? 'Receipts in range' : 'Total receipts' ?></p>
            <p class="cr-number plain"><?= number_format($count) ?></p>
        </div>
        <div>
            <p class="cr-label">Average sale</p>
            <p class="cr-number plain"><?= peso($average) ?></p>
        </div>
    </section>

    <section class="panel" aria-labelledby="list-title">
        <div class="panel-head">
            <h2 id="list-title">All sales</h2>
            <?php if ($sales): ?>
            <div class="no-print">
                <label class="sr" for="filter">Search receipts</label>
                <input class="search" id="filter" type="search" data-search-input placeholder="Search receipt or date"
                       autocomplete="off">
            </div>
            <?php endif; ?>
        </div>

        <form class="filters no-print" method="GET" action="history.php">
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= e($today) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= e($today) ?>">
            </div>
            <button class="btn primary" type="submit">Apply filter</button>
            <?php if ($filtered): ?>
                <a class="btn" href="history.php">Clear</a>
            <?php endif; ?>
            <div class="quick">
                <a href="history.php?from=<?= e($today) ?>&amp;to=<?= e($today) ?>">Today</a>
                <a href="history.php?from=<?= e(date('Y-m-01')) ?>&amp;to=<?= e($today) ?>">This month</a>
            </div>
        </form>

        <div class="table-wrap">
            <table id="salesTable">
                <thead>
                    <tr>
                        <th scope="col">Receipt No.</th>
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Items</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col" class="no-print"><span class="sr">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$sales): ?>
                    <tr>
                        <td colspan="5" class="empty">
                            <?= $filtered
                                ? 'No sales in this date range. <a href="history.php">Show all sales</a>.'
                                : 'No sales recorded yet. <a href="sales.php">Start a new sale</a>.' ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($sales as $s):
                    $ts = strtotime($s['sale_date']);
                    $dateText = $ts ? date('M j, Y g:i A', $ts) : (string)$s['sale_date'];
                ?>
                    <tr data-search="<?= e(strtolower('sale-' . $s['id'] . ' ' . $dateText)) ?>">
                        <td>SALE-<?= (int)$s['id'] ?></td>
                        <td class="muted"><?= e($dateText) ?></td>
                        <td class="num"><?= (int)$s['item_count'] ?></td>
                        <td class="num"><?= peso($s['total_amount']) ?></td>
                        <td class="no-print">
                            <a class="btn" href="history.php?receipt=<?= (int)$s['id'] ?>">View receipt</a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                    <tr id="noMatch" hidden>
                        <td colspan="5" class="empty">No receipts match your search.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($count > count($sales)): ?>
            <p class="note">Showing the latest <?= count($sales) ?> of <?= number_format($count) ?> sales.
                Use the date filter to narrow the list.</p>
        <?php endif; ?>
    </section>

<script>
(function () {
    var input = document.getElementById('filter');
    if (!input) return;
    var rows = Array.prototype.slice.call(document.querySelectorAll('#salesTable tbody tr[data-search]'));
    var none = document.getElementById('noMatch');
    input.addEventListener('input', function () {
        var term = input.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (row) {
            var match = row.getAttribute('data-search').indexOf(term) !== -1;
            row.hidden = !match;
            if (match) shown++;
        });
        none.hidden = shown !== 0;
    });
})();
</script>

<?php endif; ?>
</div>
</main>

<script src="app.js" defer></script>
</body>
</html>
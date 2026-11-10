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

/* "Today", "Yesterday" or "Nov 8, 2026" for the list */
function dayLabel(int $ts, string $today): string {
    $d = date('Y-m-d', $ts);
    if ($d === $today) { return 'Today'; }
    if ($d === date('Y-m-d', strtotime($today . ' -1 day'))) { return 'Yesterday'; }
    return date('M j, Y', $ts);
}

$error    = null;
$receipt  = null;
$items    = [];
$sales    = [];
$summary  = ['n' => 0, 'total' => 0];
$stats    = ['low' => 0];   // read by nav.php for the low-stock badge
$notFound = false;
$prevId   = null;
$nextId   = null;

$from = validDate((string)($_GET['from'] ?? ''));
$to   = validDate((string)($_GET['to'] ?? ''));
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$today = date('Y-m-d');

try {
    $stats['low'] = (int)fetchAll($conn, "SELECT COUNT(*) AS c FROM products WHERE quantity <= low_stock_level")[0]['c'];

    if (isset($_GET['receipt'])) {
        $id = filter_var($_GET['receipt'], FILTER_VALIDATE_INT);
        if ($id && $id > 0) {
            $receipt = fetchAll($conn, "SELECT * FROM sales WHERE id = ?", 'i', [$id])[0] ?? null;
            if ($receipt) {
                $items = fetchAll($conn,
                    "SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id", 'i', [$id]
                );
                // Neighbouring receipts for the Older / Newer buttons
                $p = fetchAll($conn, "SELECT MAX(id) AS m FROM sales WHERE id < ?", 'i', [$id])[0]['m'] ?? null;
                $n = fetchAll($conn, "SELECT MIN(id) AS m FROM sales WHERE id > ?", 'i', [$id])[0]['m'] ?? null;
                $prevId = $p !== null ? (int)$p : null;
                $nextId = $n !== null ? (int)$n : null;
            }
        }
        if (!$receipt) {
            $notFound = true;
        }
    }

    if (!$receipt) {
        // Range comparisons instead of DATE(sale_date) so an index on sale_date can be used
        $where = [];
        $types = '';
        $params = [];
        if ($from !== '') { $where[] = 's.sale_date >= ?'; $types .= 's'; $params[] = $from; }
        if ($to !== '')   { $where[] = 's.sale_date < ?';  $types .= 's'; $params[] = date('Y-m-d', strtotime($to . ' +1 day')); }
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

$rangeText = '';
if ($filtered) {
    $a = $from !== '' ? date('M j, Y', strtotime($from)) : 'the start';
    $b = $to   !== '' ? date('M j, Y', strtotime($to))   : 'today';
    $rangeText = $from !== '' && $from === $to ? date('M j, Y', strtotime($from)) : $a . ' to ' . $b;
}

$quick = [
    'today' => ['Today',      $today,                           $today],
    '7d'    => ['Last 7 days', date('Y-m-d', strtotime($today . ' -6 days')), $today],
    'month' => ['This month', date('Y-m-01'),                   $today],
];
$activeQuick = '';
foreach ($quick as $k => $q) {
    if ($from === $q[1] && $to === $q[2]) { $activeQuick = $k; }
}

if ($receipt) {
    $rts = strtotime($receipt['sale_date']);
    $receiptDate = $rts ? date('F j, Y g:i A', $rts) : $receipt['sale_date'];
    $receiptUnits = 0;
    foreach ($items as $it) { $receiptUnits += (int)$it['quantity']; }
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
    <style>
        /* ---------- Sales list ---------- */
        .toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .75rem 1rem; margin-bottom: 1rem; }
        .toolbar .seg { margin-right: auto; }
        .range-chip { display: inline-flex; align-items: center; gap: .5rem; margin: 0 0 1rem; padding: .35rem .5rem .35rem .85rem;
            border: 1px solid rgba(190, 182, 255, .3); border-radius: 999px; background: rgba(190, 182, 255, .08); font-size: .9rem; }
        .range-chip a { display: inline-grid; place-items: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; text-decoration: none;
            background: rgba(255, 255, 255, .12); color: inherit; line-height: 1; }
        .range-chip a:hover { background: rgba(255, 255, 255, .22); }
        .search-wrap { position: relative; }
        .search-wrap .search { padding-right: 2.6rem; }
        .search-wrap kbd { position: absolute; right: .6rem; top: 50%; transform: translateY(-50%); padding: .05rem .4rem; border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, .25); font: 600 .75rem/1.4 inherit; opacity: .7; pointer-events: none; }
        .match-count { margin: 0 0 .5rem; font-size: .85rem; opacity: .75; }

        #salesTable tbody tr { transition: background-color .15s ease; }
        #salesTable tbody tr[data-search]:hover { background: rgba(190, 182, 255, .09); }
        #salesTable tr[hidden] { display: none !important; }
        #salesTable .rc { display: flex; align-items: center; gap: .75rem; font-weight: 700; letter-spacing: .01em; }
        #salesTable .rc-icon { flex: none; display: grid; place-items: center; width: 2.25rem; height: 2.25rem; border-radius: 10px;
            background: rgba(103, 232, 249, .12); color: var(--cyan-soft, #67e8f9); }
        #salesTable .rc-icon svg { width: 1.15rem; height: 1.15rem; }
        #salesTable .when strong { display: block; font-weight: 600; }
        #salesTable .when small { opacity: .7; }
        #salesTable .pill { display: inline-block; padding: .15rem .65rem; border-radius: 999px; font-size: .85rem; font-weight: 600;
            background: rgba(190, 182, 255, .14); white-space: nowrap; }
        #salesTable .amount { font-weight: 700; font-variant-numeric: tabular-nums; }
        #salesTable thead th { position: sticky; top: 0; z-index: 1; }
        #salesTable { width: 100%; }
        /* Action column: shrink to the button's width and sit against the right edge */
        #salesTable th:last-child, #salesTable td:last-child { width: 1%; white-space: nowrap; text-align: right; padding-right: 0; }
        #salesTable td:last-child .btn { margin-left: auto; }

        .empty-state { display: grid; justify-items: center; gap: .5rem; padding: 2.5rem 1rem; text-align: center; }
        .empty-state svg { width: 3rem; height: 3rem; opacity: .5; }
        .empty-state p { margin: 0; }

        @media (max-width: 640px) {
            #salesTable thead { display: none; }
            #salesTable, #salesTable tbody { display: block; }
            #salesTable tr[data-search] { display: grid; grid-template-columns: 1fr auto; gap: .35rem 1rem; padding: .9rem 0;
                border-bottom: 1px solid rgba(255, 255, 255, .1); }
            #salesTable tr[data-search] td { display: block; padding: 0; border: 0; text-align: left; }
            #salesTable td:nth-child(1) { grid-column: 1; grid-row: 1; }
            #salesTable td:nth-child(4) { grid-column: 2; grid-row: 1; text-align: right; }
            #salesTable td:nth-child(2) { grid-column: 1; grid-row: 2; }
            #salesTable td:nth-child(3) { grid-column: 2; grid-row: 2; text-align: right; }
            #salesTable td:nth-child(5) { grid-column: 1 / -1; grid-row: 3; margin-top: .4rem; }
            #salesTable td:nth-child(5) .btn { width: 100%; justify-content: center; text-align: center; }
        }

        /* ---------- Receipt ---------- */
        .receipt-wrap { display: grid; gap: 1rem; justify-items: center; }
        .receipt-nav { display: flex; gap: .5rem; flex-wrap: wrap; justify-content: center; }
        .receipt-nav .btn[aria-disabled="true"] { opacity: .4; pointer-events: none; }

        article.receipt { position: relative; width: min(100%, 26rem); margin: 0 auto; padding: 1.75rem 1.6rem 2.25rem;
            background: #fffdf6; color: #1b1b1b; border-radius: 6px 6px 0 0;
            box-shadow: 0 24px 50px -22px rgba(0, 0, 0, .75), 0 0 0 1px rgba(0, 0, 0, .05);
            font-family: "Figtree", system-ui, sans-serif; }
        /* torn paper edge */
        article.receipt::after { content: ""; position: absolute; left: 0; right: 0; bottom: -10px; height: 10px;
            background: linear-gradient(-45deg, transparent 7px, #fffdf6 0) 0 0 / 14px 10px repeat-x,
                        linear-gradient(45deg, transparent 7px, #fffdf6 0) 0 0 / 14px 10px repeat-x; }
        article.receipt .receipt-head { text-align: center; padding-bottom: 1rem; border-bottom: 2px dashed #c9c4b3; }
        article.receipt .receipt-head h2 { margin: 0; font-family: "Bricolage Grotesque", "Figtree", sans-serif; font-size: 1.5rem; line-height: 1.15; color: #111; }
        article.receipt .receipt-head p { margin: .15rem 0 0; font-size: .9rem; color: #555; }
        article.receipt .receipt-title { margin: 1rem 0 .6rem; text-align: center; font-weight: 700; letter-spacing: .22em; text-transform: uppercase; font-size: .8rem; color: #333; }
        article.receipt .receipt-meta { display: grid; gap: .25rem; margin: 0 0 1rem; padding: 0; }
        article.receipt .receipt-meta div { display: flex; justify-content: space-between; gap: 1rem; font-size: .92rem; }
        article.receipt .receipt-meta dt { color: #666; }
        article.receipt .receipt-meta dd { margin: 0; font-weight: 700; text-align: right; }
        article.receipt table { width: 100%; border-collapse: collapse; font-size: .92rem; color: inherit; background: none; }
        article.receipt th { padding: .45rem 0; text-align: left; font-size: .72rem; letter-spacing: .1em; text-transform: uppercase;
            color: #666; border-top: 2px dashed #c9c4b3; border-bottom: 1px solid #e3dfd0; background: none; position: static; }
        article.receipt td { padding: .55rem 0; vertical-align: top; border-bottom: 1px dotted #d6d1c0; background: none; color: inherit; }
        article.receipt th.num, article.receipt td.num { text-align: right; font-variant-numeric: tabular-nums; }
        article.receipt td.item { padding-right: .6rem; font-weight: 600; overflow-wrap: anywhere; }
        article.receipt td small { display: block; font-weight: 400; color: #777; }
        article.receipt .receipt-total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 1rem; padding: .8rem 0 0;
            border-top: 2px dashed #c9c4b3; font-weight: 800; }
        article.receipt .receipt-total span:first-child { font-size: .85rem; letter-spacing: .18em; text-transform: uppercase; }
        article.receipt .receipt-total span:last-child { font-family: "Bricolage Grotesque", "Figtree", sans-serif; font-size: 1.9rem; }
        article.receipt .receipt-units { margin: .25rem 0 0; text-align: right; font-size: .85rem; color: #666; }
        article.receipt .receipt-foot { margin: 1.4rem 0 .25rem; text-align: center; font-weight: 700; }
        article.receipt .receipt-fine { margin: 0; text-align: center; font-size: .78rem; color: #777; }

        @media print {
            .receipt-nav, .receipt-wrap > .no-print { display: none !important; }
            article.receipt { width: 80mm; margin: 0 auto; padding: 0; background: #fff; box-shadow: none; border-radius: 0; }
            article.receipt::after { display: none; }
        }
        @media (prefers-reduced-motion: reduce) { #salesTable tbody tr { transition: none; } }
    </style>
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

    <div class="receipt-wrap">
        <nav class="receipt-nav no-print" aria-label="Browse receipts">
            <a class="btn" href="<?= $prevId ? 'history.php?receipt=' . $prevId : '#' ?>"<?= $prevId ? '' : ' aria-disabled="true" tabindex="-1"' ?>>&larr; Older</a>
            <a class="btn" href="<?= $nextId ? 'history.php?receipt=' . $nextId : '#' ?>"<?= $nextId ? '' : ' aria-disabled="true" tabindex="-1"' ?>>Newer &rarr;</a>
        </nav>

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
                        <th scope="col" class="num">Qty</th>
                        <th scope="col" class="num">Price</th>
                        <th scope="col" class="num">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="item"><?= e($item['product_name']) ?></td>
                        <td class="num"><?= (int)$item['quantity'] ?></td>
                        <td class="num"><?= peso($item['price']) ?></td>
                        <td class="num"><?= peso($item['subtotal']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="receipt-total">
                <span>Total</span>
                <span><?= peso($receipt['total_amount']) ?></span>
            </div>
            <p class="receipt-units"><?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?>, <?= $receiptUnits ?> <?= $receiptUnits === 1 ? 'unit' : 'units' ?></p>

            <p class="receipt-foot">Thank you for your purchase!</p>
            <p class="receipt-fine">System-generated record; not an official tax receipt.</p>
        </article>
    </div>

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
            <div>
                <h2 id="list-title">All sales</h2>
                <?php if ($filtered): ?>
                    <p class="panel-note">Showing <?= e($rangeText) ?>.</p>
                <?php endif; ?>
            </div>
            <?php if ($sales): ?>
            <div class="no-print search-wrap">
                <label class="sr" for="filter">Search receipts</label>
                <input class="search" id="filter" type="search" data-search-input placeholder="Search receipt or date"
                       autocomplete="off">
                <kbd aria-hidden="true">/</kbd>
            </div>
            <?php endif; ?>
        </div>

        <form class="filters toolbar no-print" method="GET" action="history.php" aria-label="Filter by date">
            <div class="seg" role="group" aria-label="Quick periods">
                <?php foreach ($quick as $k => $q): ?>
                    <a href="history.php?from=<?= e($q[1]) ?>&amp;to=<?= e($q[2]) ?>"<?= $activeQuick === $k ? ' aria-current="true"' : '' ?>><?= e($q[0]) ?></a>
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
            <button class="btn primary" type="submit">Apply filter</button>
            <?php if ($filtered): ?>
                <a class="btn" href="history.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($sales): ?>
            <p class="match-count no-print" id="matchCount" aria-live="polite">
                <?= count($sales) ?> <?= count($sales) === 1 ? 'receipt' : 'receipts' ?>
            </p>
        <?php endif; ?>

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
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/></svg>
                                <?php if ($filtered): ?>
                                    <p><strong>No sales in this date range.</strong></p>
                                    <p><a href="history.php">Show all sales</a></p>
                                <?php else: ?>
                                    <p><strong>No sales recorded yet.</strong></p>
                                    <p><a href="sales.php">Start a new sale</a></p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($sales as $s):
                    $ts = strtotime($s['sale_date']);
                    $dateText = $ts ? date('M j, Y g:i A', $ts) : (string)$s['sale_date'];
                    $n = (int)$s['item_count'];
                ?>
                    <tr data-search="<?= e(strtolower('sale-' . $s['id'] . ' ' . $dateText . ' ' . ($ts ? dayLabel($ts, $today) : ''))) ?>">
                        <td>
                            <span class="rc">
                                <span class="rc-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/></svg></span>
                                SALE-<?= (int)$s['id'] ?>
                            </span>
                        </td>
                        <td class="when">
                            <?php if ($ts): ?>
                                <strong><?= e(dayLabel($ts, $today)) ?></strong>
                                <small><?= e(date('g:i A', $ts)) ?></small>
                            <?php else: ?>
                                <?= e($dateText) ?>
                            <?php endif; ?>
                        </td>
                        <td class="num"><span class="pill"><?= $n ?> <?= $n === 1 ? 'item' : 'items' ?></span></td>
                        <td class="num amount"><?= peso($s['total_amount']) ?></td>
                        <td class="no-print">
                            <a class="btn" href="history.php?receipt=<?= (int)$s['id'] ?>">View receipt</a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                    <tr id="noMatch" hidden>
                        <td colspan="5" class="empty">
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                                <p><strong>No receipts match your search.</strong></p>
                            </div>
                        </td>
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
    var counter = document.getElementById('matchCount');

    function run() {
        var term = input.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (row) {
            var match = row.getAttribute('data-search').indexOf(term) !== -1;
            row.hidden = !match;
            if (match) shown++;
        });
        none.hidden = shown !== 0;
        if (counter) {
            counter.textContent = term
                ? shown + ' of ' + rows.length + ' receipts match'
                : rows.length + (rows.length === 1 ? ' receipt' : ' receipts');
        }
    }
    input.addEventListener('input', run);

    /* Press "/" to jump to the search box, Esc to clear it */
    document.addEventListener('keydown', function (e) {
        var tag = (document.activeElement && document.activeElement.tagName) || '';
        if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) {
            e.preventDefault();
            input.focus();
        } else if (e.key === 'Escape' && document.activeElement === input && input.value) {
            input.value = '';
            run();
        }
    });
})();
</script>

<?php endif; ?>
</div>
</main>

<script src="app.js" defer></script>
</body>
</html>
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

$message = "";
$receipt = null;
$receiptItems = [];
$oldRows = [];   // lets the form keep the cashier's entries after an error

/* ---------- Load a receipt ---------- */
if (isset($_GET['receipt'])) {
    $id = filter_var($_GET['receipt'], FILTER_VALIDATE_INT);

    if ($id && $id > 0) {
        $stmt = $conn->prepare("SELECT * FROM sales WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $receipt = $stmt->get_result()->fetch_assoc();

        if ($receipt) {
            $stmt = $conn->prepare(
                "SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id"
            );
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $receiptItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    if (!$receipt) {
        $message = "Receipt not found. You can start a new sale below.";
    }
}

/* ---------- Checkout ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {

    $ids = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $items = [];

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $message = "Your session expired. Please check the items and confirm again.";
    } elseif (!is_array($ids) || !is_array($qtys)
        || count($ids) < 1 || count($ids) > 100
        || count($ids) !== count($qtys)) {
        $message = "Please enter valid purchase items.";
    } else {
        foreach ($ids as $i => $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            $qty = filter_var($qtys[$i] ?? null, FILTER_VALIDATE_INT);

            $oldRows[] = [
                'id'  => $id ?: 0,
                'qty' => ($qty && $qty > 0) ? $qty : 1,
            ];

            if (!$id || $id < 1 || !$qty || $qty < 1) {
                $message = "Choose a product and valid quantity.";
                break;
            }

            $items[$id] = ($items[$id] ?? 0) + $qty;
        }
    }

    if ($message === "") {
        try {
            $conn->begin_transaction();

            $checked = [];
            $total = 0;

            $check = $conn->prepare(
                "SELECT id, product_name, quantity, unit, price
                 FROM products WHERE id = ? FOR UPDATE"
            );

            foreach ($items as $id => $qty) {
                $check->bind_param("i", $id);
                $check->execute();

                $p = $check->get_result()->fetch_assoc();

                if (!$p) {
                    throw new RuntimeException("Product not found.");
                }

                if ((int)$p['quantity'] < $qty) {
                    throw new RuntimeException(
                        $p['product_name'] . " has only " .
                        (int)$p['quantity'] . " " . $p['unit'] .
                        " available."
                    );
                }

                $price = (float)$p['price'];
                $subtotal = round($price * $qty, 2);
                $total += $subtotal;

                $checked[] = [
                    'id' => $id,
                    'name' => $p['product_name'],
                    'qty' => $qty,
                    'price' => $price,
                    'subtotal' => $subtotal
                ];
            }

            $total = round($total, 2);

            $stmt = $conn->prepare(
                "INSERT INTO sales (total_amount) VALUES (?)"
            );
            $stmt->bind_param("d", $total);
            $stmt->execute();

            $saleId = $conn->insert_id;

            $save = $conn->prepare(
                "INSERT INTO sale_items
                 (sale_id, product_id, product_name,
                  quantity, price, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            $deduct = $conn->prepare(
                "UPDATE products
                 SET quantity = quantity - ?
                 WHERE id = ? AND quantity >= ?"
            );

            $log = $conn->prepare(
                "INSERT INTO stock_transactions
                 (product_id, transaction_type, quantity, remarks)
                 VALUES (?, 'STOCK OUT', ?, ?)"
            );

            foreach ($checked as $item) {
                $pid = $item['id'];
                $name = $item['name'];
                $qty = $item['qty'];
                $price = $item['price'];
                $subtotal = $item['subtotal'];

                $save->bind_param(
                    "iisidd",
                    $saleId, $pid, $name,
                    $qty, $price, $subtotal
                );
                $save->execute();

                $deduct->bind_param("iii", $qty, $pid, $qty);
                $deduct->execute();

                if ($deduct->affected_rows !== 1) {
                    throw new RuntimeException(
                        "Stock changed. Please try again."
                    );
                }

                $remarks = "Sale #" . $saleId;
                $log->bind_param("iis", $pid, $qty, $remarks);
                $log->execute();
            }

            $conn->commit();

            header("Location: sales.php?receipt=" . $saleId);
            exit;

        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {}

            error_log($e->getMessage());

            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : "Unable to complete the sale. Please try again.";
        }
    }
}

$products = $conn->query(
    "SELECT * FROM products ORDER BY product_name"
)->fetch_all(MYSQLI_ASSOC);

/* Low-stock count for the sidebar badge */
$lowCount = 0;
foreach ($products as $p) {
    if ((int)$p['quantity'] <= (int)$p['low_stock_level']) {
        $lowCount++;
    }
}
$stats = ['low' => $lowCount];

if (!$oldRows) {
    $oldRows = [['id' => 0, 'qty' => 1]];
}

/* Quick-add buttons: best sellers first, topped up with whatever else is in stock */
$favorites = [];
try {
    $byId = [];
    foreach ($products as $p) {
        if ((int)$p['quantity'] > 0) {
            $byId[(int)$p['id']] = $p;
        }
    }
    $popular = $conn->query(
        "SELECT product_id FROM sale_items GROUP BY product_id ORDER BY SUM(quantity) DESC LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($popular as $row) {
        $pid = (int)$row['product_id'];
        if (isset($byId[$pid])) {
            $favorites[$pid] = $byId[$pid];
        }
    }
    foreach ($byId as $pid => $p) {
        if (count($favorites) >= 8) break;
        $favorites[$pid] = $favorites[$pid] ?? $p;
    }
} catch (Throwable $ex) {
    error_log('Favorites error: ' . $ex->getMessage());
}

/* One sale row; used for the first render and after an error */
function saleRow(array $products, int $selected = 0, int $qty = 1): void { ?>
    <div class="sale-row">
        <div class="field">
            <label>Product</label>
            <select name="product_id[]" class="product-select" required>
                <option value="">Choose product</option>
                <?php foreach ($products as $p):
                    $out = (int)$p['quantity'] <= 0;
                    $isSel = (int)$p['id'] === $selected;
                ?>
                <option value="<?= (int)$p['id'] ?>"
                        data-name="<?= e($p['product_name']) ?>"
                        data-stock="<?= (int)$p['quantity'] ?>"
                        data-price="<?= e($p['price']) ?>"
                        data-unit="<?= e($p['unit']) ?>"
                        <?= $isSel ? 'selected' : '' ?>
                        <?= ($out && !$isSel) ? 'disabled' : '' ?>>
                    <?= e($p['product_name']) ?>
                    <?= $out
                        ? ' (out of stock)'
                        : ' (' . (int)$p['quantity'] . ' ' . e($p['unit']) . ' left, ' . peso($p['price']) . ')' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Quantity</label>
            <input type="number" name="quantity[]" class="qty-input"
                   min="1" value="<?= $qty ?>" inputmode="numeric" required>
        </div>

        <div class="field">
            <label>Subtotal</label>
            <input class="line-total" value="₱0.00" readonly tabindex="-1">
        </div>

        <button type="button" class="btn ghost" data-remove aria-label="Remove this product">Remove</button>

        <p class="row-note" aria-live="polite"></p>
    </div>
<?php }

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
<title>Sales and Receipts | Honey's Dressed Chicken and Frozen Food</title>
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
            <h1>Sales and receipt</h1>
            <p class="dash-shop">
                <?= $receipt
                    ? 'Sale recorded. Print the receipt or start a new sale.'
                    : 'Choose products and quantities. Stock is checked before the sale is saved.' ?>
            </p>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <?php if ($receipt): ?>
                <button class="btn primary" type="button" onclick="window.print()">Print receipt</button>
                <a class="btn" href="sales.php">New sale</a>
            <?php endif; ?>
            <a class="btn" href="history.php">Sales history</a>
        </nav>
    </header>

    <?php if ($message): ?>
        <p class="alert" role="alert"><?= e($message) ?></p>
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
            <?php foreach ($receiptItems as $item): ?>
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

    <form method="POST" id="salesForm" class="pos">
        <input type="hidden" name="checkout" value="1">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <section class="panel" aria-labelledby="items-title">
            <h2 id="items-title">Customer purchase</h2>
            <p class="panel-note">Tap a product to add it, or use the rows below.</p>

            <?php if ($favorites): ?>
            <div class="chips" role="group" aria-label="Quick add">
                <?php foreach ($favorites as $f): ?>
                    <button type="button" class="chip" data-add="<?= (int)$f['id'] ?>">
                        <?= e($f['product_name']) ?><small><?= peso($f['price']) ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div id="items">
                <?php foreach ($oldRows as $row) {
                    saleRow($products, (int)$row['id'], (int)$row['qty']);
                } ?>
            </div>

            <button type="button" class="btn" id="addRow">Add another product</button>
        </section>

        <aside class="coldroom summary" aria-labelledby="summary-title">
            <h2 id="summary-title">Order summary</h2>
            <p class="panel-note" id="summaryEmpty">No items yet. Choose a product to begin.</p>
            <ul class="summary-list" id="summaryList"></ul>

            <div class="summary-total">
                <span>Total</span>
                <strong id="total">₱0.00</strong>
            </div>

            <div class="cash field">
                <label for="cashIn">Cash received <span>(optional)</span></label>
                <div class="prefix">
                    <b aria-hidden="true">₱</b>
                    <input id="cashIn" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                </div>
                <p class="change" id="changeLine" aria-live="polite"></p>
            </div>

            <p class="alert" id="formError" role="alert" hidden></p>

            <button type="submit" class="btn primary block" id="confirmBtn">
                Confirm sale and generate receipt
            </button>
        </aside>
    </form>

<script>
(function () {
    var form = document.getElementById('salesForm');
    var items = document.getElementById('items');
    var list = document.getElementById('summaryList');
    var empty = document.getElementById('summaryEmpty');
    var totalEl = document.getElementById('total');
    var errBox = document.getElementById('formError');
    var btn = document.getElementById('confirmBtn');
    var btnLabel = btn.textContent.trim();
    var cashIn = document.getElementById('cashIn');
    var changeLine = document.getElementById('changeLine');
    var lastTotal = 0;

    function peso(n) {
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function rows() {
        return Array.prototype.slice.call(items.querySelectorAll('.sale-row'));
    }
    function read(row) {
        var sel = row.querySelector('.product-select');
        var opt = sel.options[sel.selectedIndex];
        var chosen = !!sel.value;
        var qty = parseInt(row.querySelector('.qty-input').value, 10);
        return {
            row: row,
            id: sel.value,
            qty: isNaN(qty) ? 0 : qty,
            stock: chosen ? Number(opt.dataset.stock) : 0,
            price: chosen ? Number(opt.dataset.price) : 0,
            name: chosen ? opt.dataset.name : '',
            unit: chosen ? opt.dataset.unit : ''
        };
    }

    /* Recalculate everything; returns true when the sale can be submitted */
    function update() {
        var data = rows().map(read), used = {}, total = 0, ok = true;

        data.forEach(function (d) {
            if (d.id) used[d.id] = (used[d.id] || 0) + d.qty;
        });

        list.innerHTML = '';

        data.forEach(function (d) {
            var note = '', bad = false;
            var qtyInput = d.row.querySelector('.qty-input');
            qtyInput.max = d.id ? d.stock : '';

            if (!d.id || d.qty < 1) {
                bad = true;
            } else if (used[d.id] > d.stock) {
                bad = true;
                note = d.name + ' has only ' + d.stock + ' ' + d.unit + ' available.';
            }

            var sub = bad ? 0 : Math.round(d.qty * d.price * 100) / 100;
            d.row.querySelector('.line-total').value = peso(sub);
            d.row.querySelector('.row-note').textContent = note;
            d.row.classList.toggle('invalid', bad && !!d.id);

            if (bad) ok = false;
            if (d.id && !bad) {
                total += sub;
                var li = document.createElement('li');
                var label = document.createElement('span');
                var amount = document.createElement('b');
                label.textContent = d.name + ' × ' + d.qty;
                amount.textContent = peso(sub);
                li.appendChild(label);
                li.appendChild(amount);
                list.appendChild(li);
            }
        });

        empty.hidden = list.children.length > 0;
        totalEl.textContent = peso(total);
        lastTotal = total;
        showChange();
        return ok;
    }

    /* Tell the cashier how much change to give, or how much is still owed */
    function showChange() {
        var cash = parseFloat(cashIn.value);
        if (isNaN(cash) || lastTotal <= 0) {
            changeLine.textContent = '';
            changeLine.className = 'change';
            return;
        }
        var diff = Math.round((cash - lastTotal) * 100) / 100;
        changeLine.textContent = diff >= 0 ? 'Change: ' + peso(diff) : 'Still owed: ' + peso(-diff);
        changeLine.className = 'change ' + (diff >= 0 ? 'ok' : 'short');
    }

    function resetRow(row) {
        row.querySelector('.product-select').selectedIndex = 0;
        row.querySelector('.qty-input').value = 1;
    }

    document.getElementById('addRow').addEventListener('click', function () {
        var clone = rows()[0].cloneNode(true);
        resetRow(clone);
        items.appendChild(clone);
        update();
        clone.querySelector('.product-select').focus();
    });

    /* Quick-add: add the product, or raise its quantity if it's already in the sale */
    var chips = document.querySelector('.chips');
    if (chips) {
        chips.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-add]');
            if (!chip) return;
            var id = chip.getAttribute('data-add');
            var existing = rows().filter(function (r) {
                return r.querySelector('.product-select').value === id;
            })[0];
            if (existing) {
                var q = existing.querySelector('.qty-input');
                q.value = (parseInt(q.value, 10) || 0) + 1;
            } else {
                var blank = rows().filter(function (r) {
                    return !r.querySelector('.product-select').value;
                })[0];
                if (!blank) {
                    blank = rows()[0].cloneNode(true);
                    resetRow(blank);
                    items.appendChild(blank);
                }
                blank.querySelector('.product-select').value = id;
            }
            errBox.hidden = true;
            update();
        });
    }

    items.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-remove]');
        if (!remove) return;
        var row = remove.closest('.sale-row');
        if (rows().length === 1) {
            resetRow(row);
        } else {
            row.remove();
        }
        update();
    });

    form.addEventListener('input', function () { errBox.hidden = true; update(); });
    form.addEventListener('change', function () { errBox.hidden = true; update(); });

    form.addEventListener('submit', function (event) {
        if (!update()) {
            event.preventDefault();
            errBox.textContent = 'Choose a product and a valid quantity for every row, and stay within available stock.';
            errBox.hidden = false;
            var bad = rows().filter(function (r) { return !r.querySelector('.product-select').value
                || r.classList.contains('invalid'); })[0];
            if (bad) bad.querySelector('.product-select').focus();
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Saving sale…';
    });

    /* Re-enable the button if the browser restores this page via Back */
    window.addEventListener('pageshow', function () {
        btn.disabled = false;
        btn.textContent = btnLabel;
    });

    update();
})();
</script>

<?php endif; ?>
</div>
</main>
<script src="app.js" defer></script>
</body>
</html>
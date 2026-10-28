<?php
require_once "auth.php";
require_login();

require_once "helpers.php";

date_default_timezone_set('Asia/Manila');

// Make database failures throw, so a failed save is never reported as a success.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT);
$product = ($id && $id > 0)
    ? (db_all($conn, "SELECT * FROM products WHERE id = ?", 'i', [$id])[0] ?? null)
    : null;

if (!$product) {
    header('Location: products.php?msg=missing');
    exit;
}

const ADJUST_REASONS = [
    'Count correction',
    'Spoiled or damaged',
    'Returned',
    'Sample or own use',
    'Other',
];

$self    = 'edit_product.php?id=' . (int)$product['id'];
$error   = '';
$errorIn = '';   // which form the error belongs to: update, adjust or delete
$form    = [
    'product_name'    => (string)$product['product_name'],
    'category'        => (string)$product['category'],
    'unit'            => (string)$product['unit'],
    'price'           => (string)$product['price'],
    'low_stock_level' => (string)$product['low_stock_level'],
];
$adj = ['direction' => 'in', 'quantity' => '', 'reason' => ADJUST_REASONS[0], 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $errorIn = $action;

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';

    /* ---------- Save details ---------- */
    } elseif ($action === 'update') {
        $form = [
            'product_name'    => trim((string)($_POST['product_name'] ?? '')),
            'category'        => trim((string)($_POST['category'] ?? '')),
            'unit'            => trim((string)($_POST['unit'] ?? '')),
            'price'           => trim((string)($_POST['price'] ?? '')),
            'low_stock_level' => trim((string)($_POST['low_stock_level'] ?? '')),
        ];
        $price = filter_var($form['price'], FILTER_VALIDATE_FLOAT);
        $low   = filter_var($form['low_stock_level'], FILTER_VALIDATE_INT);

        if ($form['product_name'] === '' || mb_strlen($form['product_name']) > 100) {
            $error = 'Enter a product name of up to 100 characters.';
        } elseif ($form['unit'] === '' || mb_strlen($form['unit']) > 30) {
            $error = 'Enter a unit, such as pcs or kg.';
        } elseif ($price === false || $price < 0) {
            $error = 'Enter a price of zero or more.';
        } elseif ($low === false || $low < 0) {
            $error = 'Enter a low-stock level of zero or more.';
        } else {
            try {
                $stmt = $conn->prepare(
                    "UPDATE products
                     SET product_name = ?, category = ?, unit = ?, price = ?, low_stock_level = ?
                     WHERE id = ?"
                );
                $pid = (int)$product['id'];
                $stmt->bind_param("sssdii", $form['product_name'], $form['category'], $form['unit'], $price, $low, $pid);
                $stmt->execute();
                flash_set('ok', 'Saved the changes to ' . $form['product_name'] . '.');
                header('Location: ' . $self);
                exit;
            } catch (Throwable $ex) {
                error_log('Update product: ' . $ex->getMessage());
                $error = "The changes couldn't be saved. Please try again.";
            }
        }

    /* ---------- Adjust stock ---------- */
    } elseif ($action === 'adjust') {
        $adj['direction'] = (($_POST['direction'] ?? '') === 'out') ? 'out' : 'in';
        $adj['reason']    = in_array($_POST['reason'] ?? '', ADJUST_REASONS, true) ? $_POST['reason'] : ADJUST_REASONS[0];
        $adj['note']      = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 150);
        $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $adj['quantity']  = $qty ? (int)$qty : '';

        if (!$qty || $qty < 1) {
            $error = 'Enter how many units to ' . ($adj['direction'] === 'in' ? 'add.' : 'remove.');
        } else {
            try {
                $pid = (int)$product['id'];
                $conn->begin_transaction();

                $now = db_all($conn, "SELECT quantity FROM products WHERE id = ? FOR UPDATE", 'i', [$pid])[0] ?? null;
                if (!$now) {
                    throw new RuntimeException('This product no longer exists.');
                }
                if ($adj['direction'] === 'out' && (int)$now['quantity'] < $qty) {
                    throw new RuntimeException("Only " . (int)$now['quantity'] . " on hand, so you can't remove $qty.");
                }

                if ($adj['direction'] === 'in') {
                    $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                    $stmt->bind_param("ii", $qty, $pid);
                } else {
                    $stmt = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
                    $stmt->bind_param("iii", $qty, $pid, $qty);
                }
                $stmt->execute();

                $type    = $adj['direction'] === 'in' ? 'STOCK IN' : 'STOCK OUT';
                $remarks = 'Adjustment: ' . $adj['reason'] . ($adj['note'] !== '' ? ' - ' . $adj['note'] : '');
                $stmt = $conn->prepare(
                    "INSERT INTO stock_transactions (product_id, transaction_type, quantity, remarks)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("isis", $pid, $type, $qty, $remarks);
                $stmt->execute();

                $conn->commit();
                flash_set('ok', ($adj['direction'] === 'in' ? 'Added ' : 'Removed ') . number_format($qty)
                    . ' ' . $product['unit'] . ' ' . ($adj['direction'] === 'in' ? 'to ' : 'from ') . $product['product_name'] . '.');
                header('Location: ' . $self);
                exit;
            } catch (Throwable $ex) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                error_log('Adjust stock: ' . $ex->getMessage());
                $error = $ex instanceof RuntimeException ? $ex->getMessage() : "The adjustment couldn't be saved. Please try again.";
            }
        }

    /* ---------- Delete ---------- */
    } elseif ($action === 'delete') {
        if (($_POST['confirm'] ?? '') !== '1') {
            $error = 'Tick the box to confirm you want to delete this product.';
        } else {
            try {
                $pid = (int)$product['id'];
                $onReceipts = (int)db_all($conn, "SELECT COUNT(*) AS c FROM sale_items WHERE product_id = ?", 'i', [$pid])[0]['c'];
                if ($onReceipts > 0) {
                    $error = "This product is on $onReceipts past " . ($onReceipts === 1 ? 'receipt' : 'receipts')
                        . ", so it can't be deleted. Set its quantity to zero instead.";
                } else {
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("DELETE FROM stock_transactions WHERE product_id = ?");
                    $stmt->bind_param("i", $pid);
                    $stmt->execute();
                    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
                    $stmt->bind_param("i", $pid);
                    $stmt->execute();
                    $conn->commit();
                    header('Location: products.php?msg=deleted');
                    exit;
                }
            } catch (Throwable $ex) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                error_log('Delete product: ' . $ex->getMessage());
                $error = "The product couldn't be deleted. Please try again.";
            }
        }
    }
}

/* Reload so the page always shows the stored numbers. */
$product = db_all($conn, "SELECT * FROM products WHERE id = ?", 'i', [(int)$product['id']])[0] ?? $product;
$qty = (int)$product['quantity'];
$low = (int)$product['low_stock_level'];
[$fill, $mark] = gauge_pct($qty, $low);
[$level, $badgeClass, $badgeText] = stock_state($qty, $low);

$moves = [];
$lowTotal = 0;
try {
    $dateCol = movement_date_column($conn);
    $when    = $dateCol ? ", `$dateCol` AS moved_at" : "";
    $moves   = db_all($conn,
        "SELECT transaction_type, quantity, remarks$when
         FROM stock_transactions WHERE product_id = ? ORDER BY id DESC LIMIT 8",
        'i', [(int)$product['id']]
    );
    $lowTotal = (int)db_all($conn, "SELECT COUNT(*) AS c FROM products WHERE quantity <= low_stock_level")[0]['c'];
} catch (Throwable $ex) {
    error_log('Edit product extras: ' . $ex->getMessage());
}
$stats = ['low' => $lowTotal];

$flash = flash_take();
$categories = [];
try {
    foreach (db_all($conn, "SELECT DISTINCT category FROM products WHERE category <> '' ORDER BY category") as $c) {
        $categories[] = $c['category'];
    }
} catch (Throwable $ex) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit <?= e($product['product_name']) ?> | Honey's Dressed Chicken and Frozen Food</title>
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
            <h1>Edit product</h1>
            <p class="dash-shop"><?= e($product['product_name']) ?></p>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <a class="btn primary" href="stock_in.php?product=<?= (int)$product['id'] ?>">Record delivery</a>
            <a class="btn" href="products.php">Back to products</a>
        </nav>
    </header>

    <?php if ($flash): ?>
        <p class="<?= $flash['kind'] === 'ok' ? 'message' : 'alert' ?>" role="status"><?= e($flash['text']) ?></p>
    <?php endif; ?>

    <?php if ($error && $errorIn === ''): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <div class="edit-grid">
        <div class="stack">

            <form class="panel" method="POST" action="<?= e($self) ?>" aria-labelledby="details-title">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <div class="panel-head"><h2 id="details-title">Details</h2></div>

                <?php if ($error && $errorIn === 'update'): ?>
                    <p class="alert" role="alert"><?= e($error) ?></p>
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field wide">
                        <label for="product_name">Product name</label>
                        <input id="product_name" name="product_name" maxlength="100" required
                               value="<?= e($form['product_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="category">Category <span>(optional)</span></label>
                        <input id="category" name="category" maxlength="100" list="categories"
                               autocomplete="off" value="<?= e($form['category']) ?>">
                        <datalist id="categories">
                            <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="field">
                        <label for="unit">Unit</label>
                        <input id="unit" name="unit" maxlength="30" required list="units"
                               autocomplete="off" value="<?= e($form['unit']) ?>">
                        <datalist id="units">
                            <option value="pcs"><option value="kg"><option value="pack">
                            <option value="box"><option value="tray"><option value="sack">
                        </datalist>
                    </div>
                    <div class="field">
                        <label for="price">Selling price</label>
                        <div class="prefix">
                            <b aria-hidden="true">₱</b>
                            <input id="price" type="number" name="price" min="0" step="0.01"
                                   inputmode="decimal" required value="<?= e($form['price']) ?>">
                        </div>
                        <p class="hint">Past receipts keep the price they were sold at.</p>
                    </div>
                    <div class="field">
                        <label for="low_stock_level">Low-stock alert level</label>
                        <input id="low_stock_level" type="number" name="low_stock_level" min="0"
                               inputmode="numeric" required value="<?= e($form['low_stock_level']) ?>">
                        <p class="hint">Flag the product when stock falls to this number.</p>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit">Save changes</button>
                </div>
            </form>

            <form class="panel" method="POST" action="<?= e($self) ?>" aria-labelledby="adjust-title">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="adjust">
                <div class="panel-head">
                    <div>
                        <h2 id="adjust-title">Adjust stock</h2>
                        <p class="panel-note">For spoilage, recounts and returns. Every change is written to the stock log. For supplier deliveries, use Record delivery.</p>
                    </div>
                </div>

                <?php if ($error && $errorIn === 'adjust'): ?>
                    <p class="alert" role="alert"><?= e($error) ?></p>
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field wide">
                        <span class="sr" id="dirLabel">Direction</span>
                        <div class="choice" role="radiogroup" aria-labelledby="dirLabel">
                            <label><input type="radio" name="direction" value="in" <?= $adj['direction'] === 'in' ? 'checked' : '' ?>><span>Add stock</span></label>
                            <label><input type="radio" name="direction" value="out" <?= $adj['direction'] === 'out' ? 'checked' : '' ?>><span>Remove stock</span></label>
                        </div>
                    </div>
                    <div class="field">
                        <label for="adj_qty">Quantity (<?= e($product['unit']) ?>)</label>
                        <input id="adj_qty" type="number" name="quantity" min="1" inputmode="numeric"
                               required value="<?= e($adj['quantity']) ?>">
                    </div>
                    <div class="field">
                        <label for="reason">Reason</label>
                        <select id="reason" name="reason">
                            <?php foreach (ADJUST_REASONS as $r): ?>
                                <option<?= $adj['reason'] === $r ? ' selected' : '' ?>><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field wide">
                        <label for="note">Note <span>(optional)</span></label>
                        <input id="note" name="note" maxlength="150" value="<?= e($adj['note']) ?>"
                               placeholder="e.g. Freezer 2 power cut">
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit">Save adjustment</button>
                </div>
            </form>
        </div>

        <div class="stack">
            <aside class="coldroom" aria-label="Current stock">
                <p class="cr-label">In stock now</p>
                <p class="cr-number plain" style="font-size: clamp(2.4rem, 5vw, 3.4rem)">
                    <?= number_format($qty) ?> <span style="font-size: 1.1rem; font-weight: 600; color: var(--on-dark)"><?= e($product['unit']) ?></span>
                </p>
                <div class="gauge <?= $level ?>" role="img" aria-label="<?= $qty ?> in stock, alert at <?= $low ?>">
                    <i style="width: <?= $fill ?>%"></i><b style="left: <?= $mark ?>%"></b>
                </div>
                <p class="cr-sub">Alert at <?= $low ?>. Worth <?= peso($qty * (float)$product['price']) ?> at <?= peso($product['price']) ?> each.</p>
                <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
            </aside>

            <section class="panel" aria-labelledby="moves-title">
                <div class="panel-head">
                    <h2 id="moves-title">Recent stock movements</h2>
                </div>
                <?php if ($moves): ?>
                <ul class="mini">
                    <?php foreach ($moves as $m):
                        $in = move_is_in($m['transaction_type']);
                        $ts = isset($m['moved_at']) ? strtotime((string)$m['moved_at']) : false;
                    ?>
                    <li>
                        <span>
                            <?= move_note_html($m['remarks']) ?>
                            <?php if ($ts): ?><small><?= e(date('M j, Y g:i A', $ts)) ?></small><?php endif; ?>
                        </span>
                        <span class="amt <?= $in ? 'in' : 'out' ?>"><?= $in ? '+' : '-' ?><?= number_format((int)$m['quantity']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="more"><a href="movements.php">Open the full stock log</a></p>
                <?php else: ?>
                    <p class="panel-note">No movements yet. Sales, deliveries and adjustments will show up here.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <details class="panel danger no-print" <?= $errorIn === 'delete' ? 'open' : '' ?>>
        <summary>Delete this product</summary>
        <?php if ($error && $errorIn === 'delete'): ?>
            <p class="alert" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <p class="panel-note">Deleting removes the product and its stock log. Products that appear on past receipts can't be deleted, because those receipts need them.</p>
        <form method="POST" action="<?= e($self) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <label class="check">
                <input type="checkbox" name="confirm" value="1" required>
                <span>I want to delete <strong><?= e($product['product_name']) ?></strong> permanently.</span>
            </label>
            <button class="btn danger" type="submit">Delete product</button>
        </form>
    </details>
</div>
</main>
<script src="app.js" defer></script>
</body>
</html>
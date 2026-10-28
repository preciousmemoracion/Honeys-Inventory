<?php
require_once "auth.php";
require_login();

require_once "helpers.php";

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Make database failures throw, so a failed save is never reported as a success.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$message = "";
$error = "";

/* Keep what the person typed if something goes wrong. */
$selectedId = (int)filter_var($_GET['product'] ?? 0, FILTER_VALIDATE_INT);
$oldQty = "";
$oldRemarks = "";

/* A saved delivery redirects back here, so reloading the page never saves it twice. */
if (isset($_SESSION['stockin_flash'])) {
    $message = (string)$_SESSION['stockin_flash'];
    unset($_SESSION['stockin_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
    $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    $rawRemarks = trim((string)($_POST['remarks'] ?? ''));
    $cut = function_exists('mb_substr') ? mb_substr($rawRemarks, 0, 255) : substr($rawRemarks, 0, 255);
    $remarks = $cut !== '' ? $cut : 'Supplier delivery';

    $selectedId = $id ? (int)$id : 0;
    $oldQty = $qty ? (int)$qty : "";
    $oldRemarks = $rawRemarks;

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = "Your session expired. Please try again.";
    } elseif (!$id || $id < 1 || !$qty || $qty < 1) {
        $error = "Select a product and enter a valid quantity.";
    } else {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "SELECT id, product_name FROM products WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $id);
            $stmt->execute();

            $product = $stmt->get_result()->fetch_assoc();
            if (!$product) {
                throw new RuntimeException("Product not found.");
            }

            $stmt = $conn->prepare(
                "UPDATE products SET quantity = quantity + ?
                 WHERE id = ?"
            );
            $stmt->bind_param("ii", $qty, $id);
            $stmt->execute();

            $type = "STOCK IN";

            $stmt = $conn->prepare(
                "INSERT INTO stock_transactions
                 (product_id, transaction_type, quantity, remarks)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "isis", $id, $type, $qty, $remarks
            );
            $stmt->execute();

            $conn->commit();

            // Show the success message on a fresh page load, with a clean form.
            $_SESSION['stockin_flash'] = "Delivery recorded: " . number_format($qty)
                . " added to " . $product['product_name'] . ".";
            header('Location: ' . basename($_SERVER['PHP_SELF']));
            exit;

        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {}

            error_log($e->getMessage());
            $error = "Unable to record the delivery.";
        }
    }
}

$products = $conn->query(
    "SELECT * FROM products ORDER BY product_name"
);
$hasProducts = $products && $products->num_rows > 0;
$stats = ['low' => (int)$conn->query("SELECT COUNT(*) AS c FROM products WHERE quantity <= low_stock_level")->fetch_assoc()['c']];
$recent = [];
try {
    $dateCol = movement_date_column($conn);
    $when    = $dateCol ? ", t.`$dateCol` AS moved_at" : "";
    $recent  = db_all($conn,
        "SELECT t.quantity, t.remarks, p.product_name$when
         FROM stock_transactions t LEFT JOIN products p ON p.id = t.product_id
         WHERE t.transaction_type LIKE '%in'
         ORDER BY t.id DESC LIMIT 6"
    );
} catch (Throwable $ex) {
    error_log('Recent deliveries: ' . $ex->getMessage());
}
$describedBy = ($message || $error) ? ' aria-describedby="formMsg"' : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock in | Honey's Dressed Chicken and Frozen Food</title>
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
            <h1>Stock in</h1>
            <p class="dash-shop">Record what a supplier delivered. Stock levels update as soon as you save.</p>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <a class="btn" href="products.php">View products</a>
        </nav>
    </header>

    <?php if ($message): ?>
        <p class="message" role="status" id="formMsg"><span><?= e($message) ?></span></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="alert" role="alert" id="formMsg"><span><?= e($error) ?></span></p>
    <?php endif; ?>

    <div class="si-grid">

        <form method="POST" class="panel si-form" id="stockForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="panel-head">
                <h2>Delivery details</h2>
            </div>

            <?php if (!$hasProducts): ?>
                <p class="panel-note">There are no products yet. <a href="products.php#add-product">Add a product</a> before recording a delivery.</p>
            <?php endif; ?>

            <div class="field">
                <label for="product_id">Product</label>
                <select id="product_id" name="product_id" required<?= $describedBy ?><?= $hasProducts ? '' : ' disabled' ?>>
                    <option value="">Choose a product</option>
                    <?php if ($hasProducts): while ($p = $products->fetch_assoc()): ?>
                    <option value="<?= (int)$p['id'] ?>"
                            data-name="<?= e($p['product_name']) ?>"
                            data-stock="<?= (int)$p['quantity'] ?>"
                            <?= ((int)$p['id'] === $selectedId) ? 'selected' : '' ?>>
                        <?= e($p['product_name']) ?> (<?= number_format((int)$p['quantity']) ?> in stock)
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>

            <div class="field">
                <label for="quantity">Quantity received</label>
                <div class="stepper">
                    <button type="button" id="qtyDown" aria-label="Decrease quantity">&minus;</button>
                    <input id="quantity" type="number" name="quantity" min="1" inputmode="numeric"
                           value="<?= e($oldQty) ?>" required<?= $describedBy ?>>
                    <button type="button" id="qtyUp" aria-label="Increase quantity">+</button>
                </div>
            </div>

            <div class="field">
                <label for="remarks">Remarks <span>(optional)</span></label>
                <input id="remarks" name="remarks" maxlength="255" value="<?= e($oldRemarks) ?>"
                       placeholder="Supplier name or delivery details">
            </div>

            <button type="submit" class="btn primary block" id="saveBtn"<?= $hasProducts ? '' : ' disabled data-locked' ?>>Save delivery</button>
        </form>

        <aside class="coldroom si-sum" aria-label="Stock after this delivery">
            <h2>After this delivery</h2>
            <p class="si-sum-product" id="sumProduct">Choose a product to see its stock.</p>
            <dl class="si-eq">
                <div><dt>In stock now</dt><dd id="sumNow">&ndash;</dd></div>
                <div><dt>Received</dt><dd id="sumAdd">&ndash;</dd></div>
                <div class="total"><dt>New stock</dt><dd id="sumNew">&ndash;</dd></div>
            </dl>
        </aside>

    </div>

    <section class="panel" style="margin-top: 1.5rem" aria-labelledby="recent-title">
        <div class="panel-head">
            <h2 id="recent-title">Recent deliveries</h2>
            <a class="no-print" href="movements.php?type=in">See the full stock log</a>
        </div>
        <?php if ($recent): ?>
        <ul class="mini">
            <?php foreach ($recent as $r):
                $ts = isset($r['moved_at']) ? strtotime((string)$r['moved_at']) : false;
            ?>
            <li>
                <span>
                    <?= e($r['product_name'] ?? '(deleted product)') ?>
                    <small><?= e($r['remarks']) ?><?= $ts ? ', ' . e(date('M j, g:i A', $ts)) : '' ?></small>
                </span>
                <span class="amt in">+<?= number_format((int)$r['quantity']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p class="panel-note">Nothing recorded yet. Saved deliveries show up here.</p>
        <?php endif; ?>
    </section>
</div>
</main>

<script>
(function () {
    var form = document.getElementById('stockForm');
    var sel = document.getElementById('product_id');
    var qty = document.getElementById('quantity');
    var btn = document.getElementById('saveBtn');
    var sumProduct = document.getElementById('sumProduct');
    var sumNow = document.getElementById('sumNow');
    var sumAdd = document.getElementById('sumAdd');
    var sumNew = document.getElementById('sumNew');
    var DASH = '\u2013';

    function fmt(n) { return n.toLocaleString(); }

    function update() {
        var opt = sel.options[sel.selectedIndex];
        var hasProduct = opt && opt.dataset.stock !== undefined;
        var stock = hasProduct ? parseInt(opt.dataset.stock, 10) : null;
        var add = parseInt(qty.value, 10);
        var validAdd = !isNaN(add) && add > 0;

        sumProduct.textContent = hasProduct ? opt.dataset.name : 'Choose a product to see its stock.';
        sumNow.textContent = hasProduct ? fmt(stock) : DASH;
        sumAdd.textContent = validAdd ? '+' + fmt(add) : DASH;
        sumNew.textContent = (hasProduct && validAdd) ? fmt(stock + add) : DASH;
    }

    function step(delta) {
        var current = parseInt(qty.value, 10);
        if (isNaN(current)) { current = 0; }
        qty.value = Math.max(1, current + delta);
        update();
    }

    document.getElementById('qtyDown').addEventListener('click', function () { step(-1); });
    document.getElementById('qtyUp').addEventListener('click', function () { step(1); });
    sel.addEventListener('change', update);
    qty.addEventListener('input', update);

    // Stops a double-click from saving the same delivery twice.
    form.addEventListener('submit', function () {
        if (!form.checkValidity()) { return; }
        btn.disabled = true;
        btn.textContent = 'Saving\u2026';
    });
    window.addEventListener('pageshow', function () {
        btn.disabled = btn.hasAttribute('data-locked');
        btn.textContent = 'Save delivery';
        update();
    });

    update();
})();
</script>
<script src="app.js" defer></script>
</body>
</html>
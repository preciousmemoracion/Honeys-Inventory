<?php
require_once "auth.php";
require_login();

require_once "helpers.php";

date_default_timezone_set('Asia/Manila');

$msg   = $_GET['msg'] ?? '';
$error = null;
$rows  = [];

try {
    $rows = db_all($conn, "SELECT * FROM products ORDER BY product_name");
} catch (Throwable $ex) {
    error_log('Products error: ' . $ex->getMessage());
    $error = "The product list couldn't load. Check the database connection and try again.";
}

$total = count($rows);
$lowCount = $outCount = $units = 0;
$value = 0.0;
$categories = [];
foreach ($rows as $r) {
    $q = (int)$r['quantity'];
    $units += $q;
    $value += $q * (float)$r['price'];
    if ($q <= (int)$r['low_stock_level']) { $lowCount++; }
    if ($q <= 0) { $outCount++; }
    if (trim((string)$r['category']) !== '') {
        $categories[trim((string)$r['category'])] = true;
    }
}
$categories = array_keys($categories);
sort($categories);

$initStatus = in_array($_GET['status'] ?? '', ['low', 'out', 'ok'], true) ? $_GET['status'] : 'all';
$initQ      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);   // from the top-bar search
$addOpen    = ($msg === 'error') || ($total === 0 && !$error);
$stats      = ['low' => $lowCount]; // lets the sidebar show the low-stock count
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products | Honey's Dressed Chicken and Frozen Food</title>
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
            <h1>Products</h1>
            <p class="dash-shop">Add items, edit prices, and keep an eye on stock levels.</p>
            <?php if (!$error): ?>
            <p class="dash-status <?= $lowCount > 0 ? 'low' : '' ?>">
                <?= $total ?> <?= $total === 1 ? 'product' : 'products' ?> listed.
                <?php if ($lowCount > 0): ?>
                    <a href="products.php?status=low"><?= $lowCount ?> <?= $lowCount === 1 ? 'needs' : 'need' ?> restocking</a>.
                <?php else: ?>
                    All stock levels are healthy.
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <nav class="actions no-print" aria-label="Quick actions">
            <a class="btn primary" href="#add-product">Add product</a>
            <a class="btn" href="stock_in.php">Record delivery</a>
            <a class="btn" href="export.php?type=products">Export CSV</a>
        </nav>
    </header>

    <?php if ($msg === 'saved'): ?>
        <p class="message" role="status">Product added.</p>
    <?php elseif ($msg === 'updated'): ?>
        <p class="message" role="status">Product details saved.</p>
    <?php elseif ($msg === 'deleted'): ?>
        <p class="message" role="status">Product deleted.</p>
    <?php elseif ($msg === 'missing'): ?>
        <p class="alert" role="alert">That product no longer exists.</p>
    <?php elseif ($msg === 'error'): ?>
        <p class="alert" role="alert">The product couldn't be saved. Check the details and try again.</p>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="cards four">
        <div class="card">
            <h3>Product types</h3>
            <p class="number"><?= number_format($total) ?></p>
        </div>
        <div class="card">
            <h3>Units in stock</h3>
            <p class="number"><?= number_format($units) ?></p>
        </div>
        <div class="card">
            <h3>Stock value</h3>
            <p class="number"><?= peso($value) ?></p>
            <p class="sub">At selling prices</p>
        </div>
        <div class="card <?= $lowCount > 0 ? 'alert-card' : '' ?>">
            <h3>Needs restocking</h3>
            <p class="number <?= $lowCount > 0 ? 'warn' : '' ?>"><?= $lowCount ?></p>
            <?php if ($outCount > 0): ?><p class="sub"><?= $outCount ?> out of stock</p><?php endif; ?>
        </div>
    </div>

    <details class="panel no-print" id="add-product" <?= $addOpen ? 'open' : '' ?>>
        <summary>Add a product</summary>
        <p class="panel-note">New products appear in the list below and on the dashboard.</p>

        <form method="POST" action="save_products.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <!-- Default sent so save_products.php keeps working: alert level 5 -->
            <input type="hidden" name="low_stock_level" value="5">

            <div class="form-grid">
                <div class="field wide">
                    <label for="product_name">Product name</label>
                    <input id="product_name" name="product_name" maxlength="100" required
                           autocomplete="off" placeholder="e.g. Whole dressed chicken">
                </div>

                <div class="field">
                    <label for="category">Category <span>(optional)</span></label>
                    <input id="category" name="category" maxlength="100" list="categories"
                           autocomplete="off" placeholder="e.g. Chicken">
                    <datalist id="categories">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="field">
                    <label for="quantity">Quantity</label>
                    <input id="quantity" type="number" name="quantity" min="0" inputmode="numeric" required>
                    <p class="hint">Units on hand right now.</p>
                </div>

                <div class="field">
                    <label for="unit">Unit</label>
                    <input id="unit" name="unit" value="pcs" maxlength="30" list="units"
                           autocomplete="off" required>
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
                               inputmode="decimal" required>
                    </div>
                    <p class="hint">Price per unit.</p>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn primary" type="submit">Save product</button>
                <button class="btn" type="reset">Clear form</button>
            </div>
        </form>
    </details>

    <section class="panel" aria-labelledby="list-title">
        <div class="panel-head">
            <h2 id="list-title">Product list</h2>
        </div>

        <?php if ($total > 0): ?>
        <div class="toolbar no-print">
            <label class="sr" for="filter">Search products</label>
            <input class="search" id="filter" type="search" data-search-input
                   value="<?= e($initQ) ?>"
                   placeholder="Search name or category (press /)" autocomplete="off">

            <label class="sr" for="fCategory">Category</label>
            <select class="select" id="fCategory">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>"><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="sr" for="fStatus">Stock status</label>
            <select class="select" id="fStatus">
                <option value="all"<?= $initStatus === 'all' ? ' selected' : '' ?>>All stock levels</option>
                <option value="low"<?= $initStatus === 'low' ? ' selected' : '' ?>>Needs restocking</option>
                <option value="out"<?= $initStatus === 'out' ? ' selected' : '' ?>>Out of stock</option>
                <option value="ok"<?= $initStatus === 'ok' ? ' selected' : '' ?>>Available</option>
            </select>

            <label class="sr" for="fSort">Sort by</label>
            <select class="select" id="fSort">
                <option value="name">Name, A to Z</option>
                <option value="stock-asc">Stock, lowest first</option>
                <option value="stock-desc">Stock, highest first</option>
                <option value="price-desc">Price, highest first</option>
            </select>

            <p class="count" id="resultCount" role="status"></p>
        </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table id="productTable">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Category</th>
                        <th scope="col">Stock</th>
                        <th scope="col">Price</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="no-print"><span class="sr">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="empty">
                            No products yet. <a href="#add-product">Add your first product</a>.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $p):
                    $qty = (int)$p['quantity'];
                    $low = (int)$p['low_stock_level'];
                    [$fill, $mark] = gauge_pct($qty, $low);
                    [$level, $badgeClass, $badgeText] = stock_state($qty, $low);
                    $hue = crc32(strtolower((string)$p['product_name'])) % 360;
                    $ltr = mb_strtoupper(mb_substr((string)$p['product_name'], 0, 1));
                ?>
                    <tr class="<?= $level !== 'ok' ? 'is-low' : '' ?>"
                        data-name="<?= e(strtolower($p['product_name'])) ?>"
                        data-search="<?= e(strtolower($p['product_name'] . ' ' . $p['category'])) ?>"
                        data-category="<?= e(trim((string)$p['category'])) ?>"
                        data-status="<?= $level ?>"
                        data-qty="<?= $qty ?>"
                        data-price="<?= e($p['price']) ?>">
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
                        <td><?= peso($p['price']) ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                        <td class="no-print">
                            <div class="row-actions">
                                <a class="btn" href="edit_product.php?id=<?= (int)$p['id'] ?>"
                                   aria-label="Edit <?= e($p['product_name']) ?>">Edit</a>
                                <a class="btn" href="stock_in.php?product=<?= (int)$p['id'] ?>"
                                   aria-label="Receive stock for <?= e($p['product_name']) ?>">Receive</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                    <tr id="noMatch" hidden>
                        <td colspan="6" class="empty">No products match these filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>
</main>

<script>
(function () {
    var table = document.getElementById('productTable');
    var box = document.getElementById('filter');
    if (!table || !box) return;

    var body = table.tBodies[0];
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-name]'));
    var none = document.getElementById('noMatch');
    var count = document.getElementById('resultCount');
    var cat = document.getElementById('fCategory');
    var status = document.getElementById('fStatus');
    var sortBy = document.getElementById('fSort');

    function apply() {
        var term = box.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (row) {
            var s = row.dataset.status;
            var ok = (!term || row.dataset.search.indexOf(term) !== -1)
                && (!cat.value || row.dataset.category === cat.value)
                && (status.value === 'all' || (status.value === 'low' ? s !== 'ok' : s === status.value));
            row.hidden = !ok;
            if (ok) shown++;
        });
        none.hidden = shown !== 0;
        count.textContent = 'Showing ' + shown + ' of ' + rows.length;
    }

    function sortRows() {
        var k = sortBy.value;
        rows.slice().sort(function (a, b) {
            if (k === 'stock-asc')  return a.dataset.qty - b.dataset.qty || a.dataset.name.localeCompare(b.dataset.name);
            if (k === 'stock-desc') return b.dataset.qty - a.dataset.qty || a.dataset.name.localeCompare(b.dataset.name);
            if (k === 'price-desc') return b.dataset.price - a.dataset.price || a.dataset.name.localeCompare(b.dataset.name);
            return a.dataset.name.localeCompare(b.dataset.name);
        }).forEach(function (row) { body.insertBefore(row, none); });
    }

    box.addEventListener('input', apply);
    cat.addEventListener('change', apply);
    status.addEventListener('change', apply);
    sortBy.addEventListener('change', sortRows);
    apply();
})();
</script>
<script src="app.js" defer></script>
</body>
</html>
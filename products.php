<?php
require_once "auth.php";
require_login();

require_once "helpers.php";
require_once "product_image.php";

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
$addOpen    = ($msg === 'error' || $msg === 'badimage_new') || ($total === 0 && !$error);
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
    <style>
        /* Product photos */
        .pname { gap: 1rem; }
        .pname .thumb { flex: none; width: 5.5rem; height: 5.5rem; overflow: hidden; border-radius: 14px; font-size: 2rem; }
        .pname .thumb.has-img { padding: 0; background: #141A4A; box-shadow: 0 0 0 1px rgba(190, 182, 255, .3), 0 8px 20px -10px rgba(0, 0, 0, .7); }
        .pname .thumb img { display: block; width: 100%; height: 100%; object-fit: cover; border-radius: inherit; transition: transform .25s ease; }
        .pname a.zoom { position: relative; display: block; width: 100%; height: 100%; border-radius: inherit; cursor: zoom-in; }
        .pname a.zoom:hover img { transform: scale(1.08); }
        .pname a.zoom:focus-visible { outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 2px; }
        .pname > span:last-child { font-weight: 600; font-size: 1.02rem; }
        @media (max-width: 560px) { .pname .thumb { width: 4.25rem; height: 4.25rem; font-size: 1.6rem; } }
        @media (prefers-reduced-motion: reduce) { .pname .thumb img { transition: none; } }
        .photo-form { display: contents; }
        .photo-form label.btn { position: relative; cursor: pointer; }
        .photo-form label.btn:focus-within { outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 2px; }
        .photo-form input[type="file"] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; font-size: 0; }
        dialog.viewer { width: min(820px, calc(100% - 2rem)); padding: 0; border: 1px solid var(--line-strong, #4a4f8a); border-radius: 18px; overflow: hidden; background: #0E1236; color: var(--text, #fff); }
        dialog.viewer::backdrop { background: rgba(4, 6, 24, .78); }
        dialog.viewer img { display: block; width: 100%; max-height: 78dvh; object-fit: contain; background: #fff; }
        dialog.viewer p { margin: 0; padding: .85rem 1.1rem; font-weight: 600; }
        dialog.viewer button { position: absolute; top: .6rem; right: .6rem; width: 2.4rem; height: 2.4rem; border: 0; border-radius: 50%; background: rgba(10, 14, 42, .85); color: #fff; font-size: 1.3rem; cursor: pointer; }

        /* Photo picker in the Add product form */
        .photo-pick { display: flex; align-items: center; gap: 1.1rem; flex-wrap: wrap; }
        .photo-preview { flex: none; display: grid; place-items: center; width: 6rem; height: 6rem; overflow: hidden; border-radius: 16px;
            border: 2px dashed rgba(190, 182, 255, .4); background: rgba(190, 182, 255, .06); color: rgba(255, 255, 255, .55); }
        .photo-preview svg { width: 2rem; height: 2rem; }
        .photo-preview img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .photo-preview.has-img { border-style: solid; border-color: rgba(190, 182, 255, .35); }
        .photo-pick-body { display: grid; gap: .5rem; justify-items: start; min-width: 0; }
        .photo-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .photo-btn { position: relative; cursor: pointer; }
        .photo-btn:focus-within { outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 2px; }
        .photo-btn input[type="file"] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; font-size: 0; }
        .photo-pick .hint { margin: 0; }
        .photo-pick .hint.bad { color: #ff9b9b; font-weight: 600; }

        /* Add product form: spacing, sizes and colors */
        #add-product > summary { font-size: 1.15rem; font-weight: 700; letter-spacing: .01em; }
        #add-product > .panel-note { margin: .35rem 0 0; max-width: 60ch; line-height: 1.5; }
        #addForm { margin-top: 1.25rem; }
        #addForm .form-grid { gap: 1.25rem 1.5rem; }
        #addForm .field label { display: block; margin-bottom: .4rem; font-size: .9rem; font-weight: 600; }
        #addForm .field label span { font-weight: 400; opacity: .6; }
        #addForm .field > input, #addForm .field > select { min-height: 3rem; padding: .6rem 1rem; border-radius: 12px; font-size: 1rem; }
        #addForm .prefix { min-height: 3rem; border-radius: 12px; }
        #addForm .field > input:focus-visible, #addForm .field > select:focus-visible, #addForm .prefix:focus-within {
            outline: 2px solid var(--cyan-soft, #67e8f9); outline-offset: 2px; }
        #addForm .field > input::placeholder { opacity: .45; }
        #addForm .hint { margin: .4rem 0 0; font-size: .82rem; line-height: 1.4; opacity: .7; }

        /* Photo field as its own soft card */
        #addForm .photo-field { padding: 1.1rem 1.25rem; border: 1px solid rgba(190, 182, 255, .22); border-radius: 16px;
            background: linear-gradient(180deg, rgba(190, 182, 255, .08), rgba(190, 182, 255, .03)); }
        #addForm .photo-preview { width: 7rem; height: 7rem; border-radius: 18px; }
        #addForm .photo-preview svg { width: 2.4rem; height: 2.4rem; }
        #addForm .photo-preview.has-img { box-shadow: 0 10px 24px -12px rgba(0, 0, 0, .7); }
        #addForm .photo-pick .hint { opacity: .8; }

        /* Buttons: clear separation, bigger primary action */
        #addForm .form-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: 1.75rem; padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, .12); }
        #addForm .form-actions .btn { min-height: 3rem; padding-inline: 1.4rem; font-size: 1rem; }
        #addForm .form-actions .btn.primary { min-width: 11rem; font-weight: 700; }

        @media (max-width: 560px) {
            #addForm .photo-field { padding: 1rem; }
            #addForm .photo-pick { flex-direction: column; align-items: stretch; text-align: center; }
            #addForm .photo-preview { margin-inline: auto; }
            #addForm .photo-pick-body { justify-items: stretch; }
            #addForm .photo-actions .btn { flex: 1; justify-content: center; text-align: center; }
            #addForm .form-actions .btn { flex: 1 1 100%; justify-content: center; text-align: center; }
        }
    </style>
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
    <?php elseif ($msg === 'photo'): ?>
        <p class="message" role="status">Product photo saved.</p>
    <?php elseif ($msg === 'deleted'): ?>
        <p class="message" role="status">Product deleted.</p>
    <?php elseif ($msg === 'missing'): ?>
        <p class="alert" role="alert">That product no longer exists.</p>
    <?php elseif ($msg === 'badimage'): ?>
        <p class="alert" role="alert">That photo couldn't be used. Choose a JPG, PNG or WebP image under 3 MB.</p>
    <?php elseif ($msg === 'badimage_new'): ?>
        <p class="alert" role="alert">The product was added, but its photo couldn't be used. Choose a JPG, PNG or WebP image under 3 MB and add it with the Add photo button in the list.</p>
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
        <p class="panel-note">New products appear in the list below and on the dashboard. A photo is optional, and you can add or change it later from the list.</p>

        <form method="POST" action="save_products.php" enctype="multipart/form-data" id="addForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <!-- Default sent so save_products.php keeps working: alert level 5 -->
            <input type="hidden" name="low_stock_level" value="5">

            <div class="form-grid">
                <div class="field wide photo-field">
                    <label for="image">Photo <span>(optional)</span></label>
                    <div class="photo-pick">
                        <div class="photo-preview" id="photoPreview" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                        </div>
                        <div class="photo-pick-body">
                            <div class="photo-actions">
                                <span class="btn photo-btn" id="photoBtn">
                                    <span id="photoBtnText">Choose photo</span>
                                    <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp"
                                           aria-describedby="photoHint">
                                </span>
                                <button class="btn" type="button" id="photoClear" hidden>Remove</button>
                            </div>
                            <p class="hint" id="photoHint" role="status">JPG, PNG or WebP, under 3 MB.</p>
                        </div>
                    </div>
                </div>

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
                    $imgUrl = product_image_url($p['image'] ?? null);
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
                                <span class="thumb<?= $imgUrl ? ' has-img' : '' ?>" style="--h: <?= (int)$hue ?>">
                                <?php if ($imgUrl): ?>
                                    <a class="zoom" href="<?= e($imgUrl) ?>" data-zoom="<?= e($p['product_name']) ?>" aria-label="View photo of <?= e($p['product_name']) ?>"><img src="<?= e($imgUrl) ?>" alt="" loading="lazy" width="88" height="88"></a>
                                <?php else: ?>
                                    <span aria-hidden="true"><?= e($ltr) ?></span>
                                <?php endif; ?>
                                </span>
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
                                <form class="photo-form" method="POST" action="set_product_image.php" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <label class="btn"><?= $imgUrl ? 'Change photo' : 'Add photo' ?>
                                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                                               aria-label="<?= $imgUrl ? 'Change' : 'Add' ?> photo for <?= e($p['product_name']) ?>">
                                    </label>
                                </form>
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

<dialog class="viewer" id="viewer" aria-label="Product photo">
    <button type="button" id="viewerClose" aria-label="Close photo">&times;</button>
    <img id="viewerImg" alt="">
    <p id="viewerCap"></p>
</dialog>

<script>
(function () {
    /* Choose a photo: check the size, then upload it straight away */
    document.querySelectorAll('.photo-form input[type="file"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) return;
            if (f.size > 3 * 1024 * 1024) { alert('Choose a photo under 3 MB.'); input.value = ''; return; }
            input.form.submit();
        });
    });

    /* Click a product photo to see it larger */
    var viewer = document.getElementById('viewer');
    if (viewer && viewer.showModal) {
        document.querySelectorAll('a.zoom').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('viewerImg').src = a.href;
                document.getElementById('viewerCap').textContent = a.dataset.zoom;
                viewer.showModal();
            });
        });
        document.getElementById('viewerClose').addEventListener('click', function () { viewer.close(); });
        viewer.addEventListener('click', function (e) { if (e.target === viewer) viewer.close(); });
    }
})();

(function () {
    /* Add product form: pick a photo, see a preview before saving */
    var form    = document.getElementById('addForm');
    var input   = document.getElementById('image');
    if (!form || !input) return;
    var preview = document.getElementById('photoPreview');
    var hint    = document.getElementById('photoHint');
    var clear   = document.getElementById('photoClear');
    var btnText = document.getElementById('photoBtnText');
    var empty   = preview.innerHTML;
    var DEFAULT_HINT = 'JPG, PNG or WebP, under 3 MB.';
    var OK_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var objectUrl = null;

    function reset(message, bad) {
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        input.value = '';
        preview.innerHTML = empty;
        preview.classList.remove('has-img');
        clear.hidden = true;
        btnText.textContent = 'Choose photo';
        hint.textContent = message || DEFAULT_HINT;
        hint.classList.toggle('bad', !!bad);
    }

    input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (!f) { reset(); return; }
        if (OK_TYPES.indexOf(f.type) === -1) { reset('That file type can\u2019t be used. Choose a JPG, PNG or WebP image.', true); return; }
        if (f.size > 3 * 1024 * 1024) { reset('That photo is over 3 MB. Choose a smaller one.', true); return; }

        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = URL.createObjectURL(f);
        var img = new Image();
        img.alt = '';
        img.src = objectUrl;
        preview.innerHTML = '';
        preview.appendChild(img);
        preview.classList.add('has-img');
        clear.hidden = false;
        btnText.textContent = 'Change photo';
        hint.textContent = f.name + ' (' + (f.size / 1024 < 1024 ? Math.round(f.size / 1024) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB') + ')';
        hint.classList.remove('bad');
    });

    clear.addEventListener('click', function () { reset(); input.focus(); });
    form.addEventListener('reset', function () { setTimeout(function () { reset(); }, 0); });
})();

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
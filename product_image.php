<?php
/*
 * Product image helpers. Images are stored in uploads/products/ and only the file name goes in products.image.
 * Needs this column once:  ALTER TABLE products ADD COLUMN image VARCHAR(255) NULL;
 */
const PRODUCT_IMG_DIR   = __DIR__ . '/uploads/products/';
const PRODUCT_IMG_MAX   = 3 * 1024 * 1024;   // 3 MB
const PRODUCT_IMG_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/** Validates an uploaded file ($_FILES['image']) and stores it. Returns the saved file name, or null if the file is not usable. */
function save_product_image(?array $f): ?string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return null;
    if ($f['size'] <= 0 || $f['size'] > PRODUCT_IMG_MAX) return null;

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);      // real type, not the browser's claim
    if (!isset(PRODUCT_IMG_TYPES[$mime])) return null;
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[0] < 50 || $info[1] < 50 || $info[0] > 6000 || $info[1] > 6000) return null;

    if (!is_dir(PRODUCT_IMG_DIR)) {
        if (!@mkdir(PRODUCT_IMG_DIR, 0755, true)) return null;
        // Never run scripts from this folder
        @file_put_contents(PRODUCT_IMG_DIR . '.htaccess', "<FilesMatch \"\\.(php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    $name = bin2hex(random_bytes(10)) . '.' . PRODUCT_IMG_TYPES[$mime];
    return move_uploaded_file($f['tmp_name'], PRODUCT_IMG_DIR . $name) ? $name : null;
}

function delete_product_image(?string $name): void {
    $name = basename((string)$name);
    if ($name !== '' && is_file(PRODUCT_IMG_DIR . $name)) @unlink(PRODUCT_IMG_DIR . $name);
}

function product_image_url(?string $name): string {
    $name = basename(trim((string)$name));
    return ($name !== '' && is_file(PRODUCT_IMG_DIR . $name)) ? 'uploads/products/' . rawurlencode($name) : '';
}

/** Works with both PDO and mysqli connections. */
function product_get_image($conn, int $id): ?string {
    $rows = db_all($conn, "SELECT image FROM products WHERE id = " . $id);
    return $rows[0]['image'] ?? null;
}

function product_set_image($conn, int $id, ?string $name): bool {
    $st = $conn->prepare('UPDATE products SET image = ? WHERE id = ?');
    if ($conn instanceof PDO) return $st->execute([$name, $id]);
    $st->bind_param('si', $name, $id);
    return $st->execute();
}

/** Product picture (click to enlarge), or a colored tile with the first letter when there is no photo. Needs e() from helpers/db. */
if (!function_exists('product_thumb')) {
    function product_thumb(array $p, bool $zoom = true): string {
        $name = (string)($p['product_name'] ?? '');
        $hue  = crc32(strtolower($name)) % 360;
        $url  = product_image_url($p['image'] ?? null);
        $h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            $ltr = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));
            return '<span class="thumb" style="--h: ' . $hue . '" aria-hidden="true">' . $h($ltr) . '</span>';
        }
        $img = '<img src="' . $h($url) . '" alt="" loading="lazy" width="88" height="88">';
        $inner = $zoom
            ? '<a class="zoom" href="' . $h($url) . '" data-zoom="' . $h($name) . '" aria-label="View photo of ' . $h($name) . '">' . $img . '</a>'
            : $img;
        return '<span class="thumb has-img" style="--h: ' . $hue . '">' . $inner . '</span>';
    }
}
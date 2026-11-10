<?php
require_once "auth.php";
require_login();
require_once "helpers.php";
require_once "product_image.php";

function back(string $msg): void { header('Location: products.php?msg=' . $msg); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) back('error');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) back('missing');

try {
    $old = product_get_image($conn, $id);
    $new = save_product_image($_FILES['image'] ?? null);
    if ($new === null) back('badimage');

    if (!product_set_image($conn, $id, $new)) {
        delete_product_image($new);
        back('error');
    }
    delete_product_image($old);   // remove the previous photo once the new one is saved
    back('photo');
} catch (Throwable $ex) {
    error_log('Product image error: ' . $ex->getMessage());
    back('error');
}
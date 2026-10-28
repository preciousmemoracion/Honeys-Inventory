<?php
require_once "auth.php";
require_login();

require_once "db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: products.php");
    exit;
}

// Only accept the form from our own page.
if (!csrf_check($_POST['csrf'] ?? null)) {
    header("Location: products.php?msg=error");
    exit;
}

$name = trim($_POST['product_name'] ?? '');
$category = trim($_POST['category'] ?? '');
$quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
$unit = trim($_POST['unit'] ?? 'pcs');
$price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
$low = filter_var($_POST['low_stock_level'] ?? null, FILTER_VALIDATE_INT);

if (
    $name === '' || $quantity === false || $quantity === null ||
    $quantity < 0 || $unit === '' ||
    $price === false || $price === null || $price < 0 ||
    $low === false || $low === null || $low < 0
) {
    header("Location: products.php?msg=error");
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO products
     (product_name, category, quantity, unit, price, low_stock_level)
     VALUES (?, ?, ?, ?, ?, ?)"
);

$stmt->bind_param(
    "ssisdi",
    $name,
    $category,
    $quantity,
    $unit,
    $price,
    $low
);

try {
    $stmt->execute();
} catch (Throwable $ex) {
    error_log('Save product error: ' . $ex->getMessage());
    header("Location: products.php?msg=error");
    exit;
}

header("Location: products.php?msg=saved");
exit;
<?php
// No blank line before the opening tag and no closing tag: any stray output here
// would break redirects and file downloads on every page that includes this file.
$host = "localhost";
$user = "root";
$password = "";
$database = "honeys_inventory";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso($amount) {
    return '₱' . number_format((float)$amount, 2);
}

/* Compact amounts for chart labels: ₱950, ₱1.2k, ₱3.4M */
function peso_short($amount) {
    $n = (float)$amount;
    if ($n >= 1000000) return '₱' . round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return '₱' . round($n / 1000, 1) . 'k';
    return '₱' . number_format($n, $n == floor($n) ? 0 : 2);
}
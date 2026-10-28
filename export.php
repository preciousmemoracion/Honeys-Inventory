<?php
/*
 * CSV downloads:
 *   export.php?type=products
 *   export.php?type=sales&from=2026-10-01&to=2026-10-21       (one row per receipt)
 *   export.php?type=items&from=...&to=...                      (one row per product sold)
 *   export.php?type=movements[&dir=in|out][&product=ID][&from=...&to=...]
 */
require_once "auth.php";
require_login();

require_once "helpers.php";

date_default_timezone_set('Asia/Manila');

$type = (string)($_GET['type'] ?? '');
$from = valid_date((string)($_GET['from'] ?? ''));
$to   = valid_date((string)($_GET['to'] ?? ''));
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$stamp = date('Y-m-d');

/* Date conditions on a column such as "s.sale_date". */
function date_filter(string $column, string $from, string $to, string &$types, array &$params): array {
    $where = [];
    if ($from !== '') { $where[] = "DATE($column) >= ?"; $types .= 's'; $params[] = $from; }
    if ($to !== '')   { $where[] = "DATE($column) <= ?"; $types .= 's'; $params[] = $to; }
    return $where;
}

try {
    if ($type === 'products') {
        $rows = [];
        foreach (db_all($conn, "SELECT * FROM products ORDER BY product_name") as $p) {
            $rows[] = [
                $p['product_name'], $p['category'], (int)$p['quantity'], $p['unit'],
                number_format((float)$p['price'], 2, '.', ''), (int)$p['low_stock_level'],
                stock_state((int)$p['quantity'], (int)$p['low_stock_level'])[2],
                number_format((int)$p['quantity'] * (float)$p['price'], 2, '.', ''),
            ];
        }
        send_csv("products-$stamp.csv",
            ['Product', 'Category', 'Quantity', 'Unit', 'Price', 'Low-stock level', 'Status', 'Stock value'], $rows);
    }

    if ($type === 'sales') {
        $types = ''; $params = [];
        $where = date_filter('s.sale_date', $from, $to, $types, $params);
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = [];
        foreach (db_all($conn,
            "SELECT s.id, s.sale_date, s.total_amount,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
             FROM sales s $w ORDER BY s.id DESC", $types, $params) as $s) {
            $rows[] = ['SALE-' . (int)$s['id'], $s['sale_date'], (int)$s['item_count'],
                       number_format((float)$s['total_amount'], 2, '.', '')];
        }
        send_csv("sales-$stamp.csv", ['Receipt', 'Date', 'Items', 'Total'], $rows);
    }

    if ($type === 'items') {
        $types = ''; $params = [];
        $where = date_filter('s.sale_date', $from, $to, $types, $params);
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = [];
        foreach (db_all($conn,
            "SELECT s.id, s.sale_date, si.product_name, si.quantity, si.price, si.subtotal
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             $w ORDER BY s.id DESC, si.id", $types, $params) as $i) {
            $rows[] = ['SALE-' . (int)$i['id'], $i['sale_date'], $i['product_name'], (int)$i['quantity'],
                       number_format((float)$i['price'], 2, '.', ''), number_format((float)$i['subtotal'], 2, '.', '')];
        }
        send_csv("sale-items-$stamp.csv", ['Receipt', 'Date', 'Product', 'Quantity', 'Price', 'Subtotal'], $rows);
    }

    if ($type === 'movements') {
        $dateCol = movement_date_column($conn);
        $types = ''; $params = [];
        $where = [];
        $dir = (string)($_GET['dir'] ?? '');
        if ($dir === 'in')  { $where[] = "t.transaction_type LIKE '%in'"; }
        if ($dir === 'out') { $where[] = "t.transaction_type LIKE '%out'"; }
        $pid = (int)filter_var($_GET['product'] ?? 0, FILTER_VALIDATE_INT);
        if ($pid > 0) { $where[] = 't.product_id = ?'; $types .= 'i'; $params[] = $pid; }
        if ($dateCol) { $where = array_merge($where, date_filter("t.`$dateCol`", $from, $to, $types, $params)); }
        $w    = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $when = $dateCol ? ", t.`$dateCol` AS moved_at" : '';
        $rows = [];
        foreach (db_all($conn,
            "SELECT t.transaction_type, t.quantity, t.remarks, p.product_name$when
             FROM stock_transactions t LEFT JOIN products p ON p.id = t.product_id
             $w ORDER BY t.id DESC", $types, $params) as $m) {
            $row = [$m['product_name'] ?? '(deleted product)', $m['transaction_type'], (int)$m['quantity'], $m['remarks']];
            if ($dateCol) { array_unshift($row, $m['moved_at']); }
            $rows[] = $row;
        }
        $header = ['Product', 'Type', 'Quantity', 'Note'];
        if ($dateCol) { array_unshift($header, 'When'); }
        send_csv("stock-log-$stamp.csv", $header, $rows);
    }
} catch (Throwable $ex) {
    error_log('Export error: ' . $ex->getMessage());
    http_response_code(500);
    exit('The export could not be created. Check the database connection and try again.');
}

http_response_code(400);
exit('Unknown export type.');
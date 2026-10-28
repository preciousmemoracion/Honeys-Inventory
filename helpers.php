<?php
/*
 * Helpers shared by the newer pages (reports, stock log, exports, product editing).
 * Include it after auth.php:  require_once "helpers.php";
 */
require_once __DIR__ . "/db.php";

/* Run a prepared query and return every row. */
function db_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/* "2026-10-21" or ''. Anything else is ignored. */
function valid_date(string $d): string {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
}

/* [level, badge class, label] for a quantity and its alert level. */
function stock_state(int $qty, int $low): array {
    if ($qty <= 0)    return ['out', 'out', 'Out of stock'];
    if ($qty <= $low) return ['low', 'low', 'Low stock'];
    return ['ok', 'available', 'Available'];
}

/* Gauge fill and alert-tick positions, both as whole percentages. */
function gauge_pct(int $qty, int $low): array {
    $scale = max($low * 3, $qty, 1);
    return [(int)round($qty / $scale * 100), (int)round($low / $scale * 100)];
}

/* One-shot messages that survive a redirect. */
function flash_set(string $kind, string $text): void {
    $_SESSION['flash'] = ['kind' => $kind, 'text' => $text];
}
function flash_take(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}

/*
 * The stock_transactions table is written to by the sales and stock-in pages, but its
 * date column name isn't known here, so look for a common one. Returns null if none.
 */
function movement_date_column(mysqli $conn): ?string {
    static $col = false;
    if ($col !== false) {
        return $col;
    }
    $col = null;
    try {
        $names = [];
        $res = $conn->query("SHOW COLUMNS FROM stock_transactions");
        while ($r = $res->fetch_assoc()) {
            $names[strtolower($r['Field'])] = $r['Field'];
        }
        foreach (['created_at', 'transaction_date', 'date_created', 'created', 'created_on',
                  'transaction_time', 'datetime', 'timestamp', 'date'] as $c) {
            if (isset($names[$c])) {
                $col = $names[$c];
                break;
            }
        }
    } catch (Throwable $ex) {
        error_log('movement_date_column: ' . $ex->getMessage());
    }
    return $col;
}

/* A stock_transactions type like "STOCK IN" or "STOCK OUT". */
function move_is_in(string $type): bool {
    return (bool)preg_match('/in$/i', trim($type));
}

/* "Sale #12" in a movement note becomes a link to that receipt. */
function move_note_html(?string $note): string {
    $note = (string)$note;
    if (preg_match('/^Sale #(\d+)$/', $note, $m)) {
        return '<a href="history.php?receipt=' . (int)$m[1] . '">' . e($note) . '</a>';
    }
    return e($note);
}

/* A report period from the query string: preset or custom, never more than a year. */
function report_range(): array {
    $today  = date('Y-m-d');
    $preset = (string)($_GET['range'] ?? '');
    $from   = valid_date((string)($_GET['from'] ?? ''));
    $to     = valid_date((string)($_GET['to'] ?? ''));

    if ($from !== '' || $to !== '') {
        $preset = 'custom';
        if ($to === '' || $to > $today)  $to = $today;
        if ($from === '')                $from = date('Y-m-d', strtotime($to . ' -29 days'));
        if ($from > $to)                 [$from, $to] = [$to, $from];
    } elseif ($preset === '7d') {
        [$from, $to] = [date('Y-m-d', strtotime('-6 days')), $today];
    } elseif ($preset === 'month') {
        [$from, $to] = [date('Y-m-01'), $today];
    } elseif ($preset === 'last_month') {
        [$from, $to] = [date('Y-m-01', strtotime('first day of last month')),
                        date('Y-m-t',  strtotime('last day of last month'))];
    } else {
        $preset = '30d';
        [$from, $to] = [date('Y-m-d', strtotime('-29 days')), $today];
    }

    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($days > 366) {
        $from = date('Y-m-d', strtotime($to . ' -365 days'));
        $days = 366;
    }
    return [$from, $to, $preset, $days];
}

/* Spreadsheet programs run text that starts with = + - @ as a formula; defuse it. */
function csv_safe($v): string {
    $s = (string)$v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) {
        return "'" . $s;
    }
    return $s;
}

/* Send a CSV file (opens cleanly in Excel) and stop. */
function send_csv(string $filename, array $header, array $rows): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', $row), ',', '"', '\\');
    }
    fclose($out);
    exit;
}
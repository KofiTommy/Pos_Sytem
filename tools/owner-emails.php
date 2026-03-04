<?php
/**
 * Owner email export utility.
 *
 * Usage:
 *   php tools/owner-emails.php
 *   php tools/owner-emails.php --format=csv
 *   php tools/owner-emails.php --format=json
 *   php tools/owner-emails.php --format=csv --out="C:\Users\you\Downloads\owner-emails.csv"
 *   php tools/owner-emails.php --include-inactive
 */

declare(strict_types=1);

function print_usage(): void
{
    $usage = <<<TXT
Owner Email Export
------------------
php tools/owner-emails.php [options]

Options:
  --format=table|csv|json   Output format (default: table)
  --out=PATH                Write output to file instead of stdout
  --include-inactive        Include inactive businesses
  --help                    Show this help message
TXT;
    echo $usage . PHP_EOL;
}

function parse_args(array $argv): array
{
    $options = [
        'format' => 'table',
        'out' => '',
        'include_inactive' => false,
        'help' => false
    ];

    for ($i = 1; $i < count($argv); $i += 1) {
        $arg = (string)$argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--include-inactive') {
            $options['include_inactive'] = true;
            continue;
        }
        if (strpos($arg, '--format=') === 0) {
            $options['format'] = strtolower(trim(substr($arg, 9)));
            continue;
        }
        if (strpos($arg, '--out=') === 0) {
            $options['out'] = trim(substr($arg, 6));
            continue;
        }
        if ($arg === '--format' && isset($argv[$i + 1])) {
            $options['format'] = strtolower(trim((string)$argv[$i + 1]));
            $i += 1;
            continue;
        }
        if ($arg === '--out' && isset($argv[$i + 1])) {
            $options['out'] = trim((string)$argv[$i + 1]);
            $i += 1;
            continue;
        }
    }

    return $options;
}

function fetch_owner_rows(mysqli $conn, bool $includeInactive): array
{
    $sql = "
        SELECT
            b.id AS business_id,
            b.business_code,
            b.business_name,
            b.status AS business_status,
            u.id AS owner_user_id,
            u.username AS owner_username,
            u.email AS owner_email,
            LOWER(COALESCE(u.role, '')) AS owner_role,
            u.created_at AS owner_created_at
        FROM users u
        INNER JOIN businesses b ON b.id = u.business_id
        WHERE LOWER(COALESCE(u.role, '')) IN ('owner', 'admin')
    ";

    if (!$includeInactive) {
        $sql .= " AND LOWER(COALESCE(b.status, '')) = 'active' ";
    }

    $sql .= " ORDER BY b.business_name ASC, u.id ASC ";

    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Failed to query owner emails.');
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'business_id' => (string)intval($row['business_id'] ?? 0),
            'business_code' => (string)($row['business_code'] ?? ''),
            'business_name' => (string)($row['business_name'] ?? ''),
            'business_status' => (string)($row['business_status'] ?? ''),
            'owner_user_id' => (string)intval($row['owner_user_id'] ?? 0),
            'owner_username' => (string)($row['owner_username'] ?? ''),
            'owner_email' => (string)($row['owner_email'] ?? ''),
            'owner_role' => (string)($row['owner_role'] ?? ''),
            'owner_created_at' => (string)($row['owner_created_at'] ?? '')
        ];
    }

    return $rows;
}

function build_csv(array $rows): string
{
    $headers = [
        'business_id',
        'business_code',
        'business_name',
        'business_status',
        'owner_user_id',
        'owner_username',
        'owner_email',
        'owner_role',
        'owner_created_at'
    ];

    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        throw new RuntimeException('Unable to build CSV output.');
    }

    fputcsv($fh, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($fh, [
            $row['business_id'],
            $row['business_code'],
            $row['business_name'],
            $row['business_status'],
            $row['owner_user_id'],
            $row['owner_username'],
            $row['owner_email'],
            $row['owner_role'],
            $row['owner_created_at']
        ], ',', '"', '\\');
    }

    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return (string)$csv;
}

function build_table(array $rows): string
{
    $columns = [
        'business_code',
        'business_name',
        'owner_username',
        'owner_email',
        'owner_role',
        'business_status'
    ];

    if (empty($rows)) {
        return "No owner emails found.\n";
    }

    $widths = [];
    foreach ($columns as $col) {
        $widths[$col] = strlen($col);
    }

    foreach ($rows as $row) {
        foreach ($columns as $col) {
            $value = (string)($row[$col] ?? '');
            $len = strlen($value);
            if ($len > $widths[$col]) {
                $widths[$col] = $len;
            }
        }
    }

    $lineParts = [];
    $headerParts = [];
    foreach ($columns as $col) {
        $lineParts[] = str_repeat('-', $widths[$col]);
        $headerParts[] = str_pad($col, $widths[$col]);
    }

    $out = [];
    $out[] = implode(' | ', $headerParts);
    $out[] = implode('-+-', $lineParts);

    foreach ($rows as $row) {
        $parts = [];
        foreach ($columns as $col) {
            $parts[] = str_pad((string)($row[$col] ?? ''), $widths[$col]);
        }
        $out[] = implode(' | ', $parts);
    }

    return implode(PHP_EOL, $out) . PHP_EOL;
}

try {
    $options = parse_args($argv);
    if ($options['help']) {
        print_usage();
        exit(0);
    }

    $format = (string)$options['format'];
    if (!in_array($format, ['table', 'csv', 'json'], true)) {
        throw new InvalidArgumentException('Invalid --format. Use table, csv, or json.');
    }

    $rootDir = realpath(__DIR__ . '/..');
    if ($rootDir === false) {
        throw new RuntimeException('Unable to resolve project root.');
    }

    require $rootDir . '/php/db-connection.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is not available.');
    }

    $rows = fetch_owner_rows($conn, (bool)$options['include_inactive']);
    $payload = '';

    if ($format === 'json') {
        $payload = json_encode([
            'total' => count($rows),
            'rows' => $rows
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } elseif ($format === 'csv') {
        $payload = build_csv($rows);
    } else {
        $payload = build_table($rows);
        $payload .= 'Total owners: ' . count($rows) . PHP_EOL;
    }

    $outputPath = trim((string)$options['out']);
    if ($outputPath !== '') {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            throw new RuntimeException("Output directory does not exist: {$dir}");
        }
        file_put_contents($outputPath, $payload);
        echo "Export complete: {$outputPath}" . PHP_EOL;
    } else {
        echo $payload;
    }

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'owner-emails.php: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}



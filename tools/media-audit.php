<?php
/**
 * Media integrity audit for POS images.
 *
 * Usage:
 *   php tools/media-audit.php
 *   php tools/media-audit.php --json
 *   php tools/media-audit.php --fix-sql
 *   php tools/media-audit.php --dump=C:\path\dump.sql
 *   php tools/media-audit.php --dump C:\path\dump.sql --fix-sql
 */

declare(strict_types=1);

function normalize_image_name(string $value): string
{
    $raw = str_replace('\\', '/', trim($value));
    $raw = explode('?', $raw, 2)[0];
    $raw = explode('#', $raw, 2)[0];
    $base = basename($raw);
    $base = preg_replace('/[\x00-\x1F\x7F]/', '', $base ?? '');
    $base = trim((string)$base);
    if (strlen($base) > 255) {
        $base = substr($base, 0, 255);
    }
    return $base;
}

function parse_dump_arg(array $argv): string
{
    for ($i = 1; $i < count($argv); $i += 1) {
        $arg = (string)$argv[$i];
        if (strpos($arg, '--dump=') === 0) {
            return trim(substr($arg, 7));
        }
        if ($arg === '--dump' && isset($argv[$i + 1])) {
            return trim((string)$argv[$i + 1]);
        }
    }
    return '';
}

function build_available_file_map(string $imagesDir): array
{
    $available = [];
    $files = scandir($imagesDir);
    if (!is_array($files)) {
        return $available;
    }
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $imagesDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }
        $available[strtolower($file)] = $file;
    }
    return $available;
}

function default_image_aliases(): array
{
    return [
        'bottle.jpg' => 'pexels-jonathan-nenemann-12114822.jpg',
        'diapers.jpg' => 'pexels-shvetsa-3845457.jpg',
        'clothing.jpg' => 'pexels-nappy-2480948.jpg',
        'monitor.jpg' => 'pexels-rdne-6849259.jpg',
        'teething.jpg' => 'pexels-public-domain-pictures-41222.jpg',
        'bathtub.jpg' => 'pexels-shvetsa-3845420.jpg',
        'nursing-pillow.jpg' => 'pexels-polina-tankilevitch-3875080.jpg',
        'wipes.jpg' => 'pexels-ismaelabdalnabystudio-20387764.jpg',
        'stroller.jpg' => 'pexels-fotios-photos-5435599.jpg',
        'sheets.jpg' => 'pexels-nerosable-19015553.jpg'
    ];
}

function is_image_resolvable(string $name, array $availableFiles, array $aliases): bool
{
    $lower = strtolower($name);
    if (isset($availableFiles[$lower])) {
        return true;
    }

    if (isset($aliases[$lower])) {
        $target = strtolower((string)$aliases[$lower]);
        return isset($availableFiles[$target]);
    }

    return false;
}

function split_sql_tuples(string $valuesBlock): array
{
    $tuples = [];
    $depth = 0;
    $start = -1;
    $inString = false;
    $escaped = false;
    $length = strlen($valuesBlock);

    for ($i = 0; $i < $length; $i += 1) {
        $ch = $valuesBlock[$i];

        if ($inString) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($ch === "'") {
                $inString = false;
            }
            continue;
        }

        if ($ch === "'") {
            $inString = true;
            continue;
        }
        if ($ch === '(') {
            if ($depth === 0) {
                $start = $i;
            }
            $depth += 1;
            continue;
        }
        if ($ch === ')') {
            $depth -= 1;
            if ($depth === 0 && $start >= 0) {
                $tuples[] = substr($valuesBlock, $start, $i - $start + 1);
                $start = -1;
            }
        }
    }

    return $tuples;
}

function split_sql_fields(string $tuple): array
{
    $inner = trim($tuple);
    if (strlen($inner) >= 2 && $inner[0] === '(' && $inner[strlen($inner) - 1] === ')') {
        $inner = substr($inner, 1, -1);
    }

    $fields = [];
    $current = '';
    $inString = false;
    $escaped = false;
    $length = strlen($inner);

    for ($i = 0; $i < $length; $i += 1) {
        $ch = $inner[$i];
        if ($inString) {
            $current .= $ch;
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($ch === "'") {
                $inString = false;
            }
            continue;
        }

        if ($ch === "'") {
            $inString = true;
            $current .= $ch;
            continue;
        }
        if ($ch === ',') {
            $fields[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $ch;
    }

    if (trim($current) !== '' || $inner !== '') {
        $fields[] = trim($current);
    }

    return $fields;
}

function parse_sql_value(string $token): string
{
    $trimmed = trim($token);
    if ($trimmed === '' || strcasecmp($trimmed, 'NULL') === 0) {
        return '';
    }
    if (strlen($trimmed) >= 2 && $trimmed[0] === "'" && $trimmed[strlen($trimmed) - 1] === "'") {
        $value = substr($trimmed, 1, -1);
        return stripcslashes($value);
    }
    return $trimmed;
}

function parse_insert_image_values_from_dump(string $dump, string $table, string $column): array
{
    $pattern = '/INSERT\s+INTO\s+`' . preg_quote($table, '/') . '`\s*\((.*?)\)\s*VALUES\s*(.*?);/is';
    preg_match_all($pattern, $dump, $insertMatches, PREG_SET_ORDER);

    $collected = [];
    foreach ($insertMatches as $match) {
        $columnsRaw = (string)($match[1] ?? '');
        $valuesRaw = (string)($match[2] ?? '');
        preg_match_all('/`([^`]+)`/', $columnsRaw, $colMatches);
        $columns = $colMatches[1] ?? [];
        $columnIndex = array_search($column, $columns, true);
        if ($columnIndex === false) {
            continue;
        }

        $tuples = split_sql_tuples($valuesRaw);
        foreach ($tuples as $tuple) {
            $fields = split_sql_fields($tuple);
            if (!array_key_exists((int)$columnIndex, $fields)) {
                continue;
            }
            $rawValue = parse_sql_value((string)$fields[(int)$columnIndex]);
            $name = normalize_image_name($rawValue);
            if ($name !== '') {
                $collected[] = $name;
            }
        }
    }

    return array_values(array_unique($collected));
}

function build_missing_from_dump(string $dumpPath, array $availableFiles, array $aliases): array
{
    $dump = file_get_contents($dumpPath);
    if ($dump === false) {
        throw new RuntimeException('Unable to read dump file');
    }

    $productNames = parse_insert_image_values_from_dump($dump, 'products', 'image');
    $logoNames = parse_insert_image_values_from_dump($dump, 'business_settings', 'logo_filename');

    $missingProducts = [];
    foreach ($productNames as $name) {
        if (!is_image_resolvable($name, $availableFiles, $aliases)) {
            $missingProducts[] = ['id' => 0, 'business_id' => 0, 'image' => $name];
        }
    }

    $missingLogos = [];
    foreach ($logoNames as $name) {
        if (!isset($availableFiles[strtolower($name)])) {
            $missingLogos[] = ['business_id' => 0, 'logo_filename' => $name];
        }
    }

    return [$missingProducts, $missingLogos];
}

function build_missing_from_db(mysqli $conn, array $availableFiles, array $aliases): array
{
    $missingProducts = [];
    $missingLogos = [];

    $productStmt = $conn->prepare(
        "SELECT id, business_id, image
         FROM products
         WHERE image IS NOT NULL AND TRIM(image) <> ''"
    );
    if ($productStmt) {
        $productStmt->execute();
        $result = $productStmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $image = normalize_image_name((string)($row['image'] ?? ''));
                if ($image === '') {
                    continue;
                }
                if (is_image_resolvable($image, $availableFiles, $aliases)) {
                    continue;
                }
                $missingProducts[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'business_id' => (int)($row['business_id'] ?? 0),
                    'image' => $image
                ];
            }
        }
        $productStmt->close();
    }

    $logoStmt = $conn->prepare(
        "SELECT business_id, logo_filename
         FROM business_settings
         WHERE logo_filename IS NOT NULL AND TRIM(logo_filename) <> ''"
    );
    if ($logoStmt) {
        $logoStmt->execute();
        $result = $logoStmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $logo = normalize_image_name((string)($row['logo_filename'] ?? ''));
                if ($logo === '') {
                    continue;
                }
                if (isset($availableFiles[strtolower($logo)])) {
                    continue;
                }
                $missingLogos[] = [
                    'business_id' => (int)($row['business_id'] ?? 0),
                    'logo_filename' => $logo
                ];
            }
        }
        $logoStmt->close();
    }

    return [$missingProducts, $missingLogos];
}

function escape_sql_literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

$rootDir = realpath(__DIR__ . '/..');
if ($rootDir === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

$imagesDir = realpath($rootDir . '/assets/images');
if ($imagesDir === false || !is_dir($imagesDir)) {
    fwrite(STDERR, "Images directory missing: {$rootDir}/assets/images\n");
    exit(1);
}

$emitJson = in_array('--json', $argv, true);
$emitFixSql = in_array('--fix-sql', $argv, true);
$dumpPath = parse_dump_arg($argv);
$availableFiles = build_available_file_map($imagesDir);
$aliases = default_image_aliases();

try {
    if ($dumpPath !== '') {
        if (!is_file($dumpPath) || !is_readable($dumpPath)) {
            throw new RuntimeException("Dump file not readable: {$dumpPath}");
        }
        [$missingProducts, $missingLogos] = build_missing_from_dump($dumpPath, $availableFiles, $aliases);
        $source = 'dump';
    } else {
        try {
            require $rootDir . '/php/db-connection.php';
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage()
                . '. Use --dump=<path-to-sql> if DB is not imported yet.'
            );
        }
        if (!isset($conn) || !($conn instanceof mysqli)) {
            throw new RuntimeException('Database connection is not available.');
        }
        [$missingProducts, $missingLogos] = build_missing_from_db($conn, $availableFiles, $aliases);
        $source = 'database';
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$summary = [
    'source' => $source,
    'available_files' => count($availableFiles),
    'missing_product_images' => count($missingProducts),
    'missing_logos' => count($missingLogos)
];

if ($emitJson) {
    echo json_encode([
        'summary' => $summary,
        'missing_products' => $missingProducts,
        'missing_logos' => $missingLogos
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Media Audit Summary" . PHP_EOL;
    echo "-------------------" . PHP_EOL;
    echo "Source: {$summary['source']}" . PHP_EOL;
    echo "Available files: {$summary['available_files']}" . PHP_EOL;
    echo "Missing product images: {$summary['missing_product_images']}" . PHP_EOL;
    echo "Missing logos: {$summary['missing_logos']}" . PHP_EOL;

    if (!empty($missingProducts)) {
        echo PHP_EOL . "Missing Product Images" . PHP_EOL;
        foreach ($missingProducts as $item) {
            $prefix = ($item['id'] > 0 || $item['business_id'] > 0)
                ? "product_id={$item['id']}, business_id={$item['business_id']}, "
                : '';
            echo " - {$prefix}image={$item['image']}" . PHP_EOL;
        }
    }

    if (!empty($missingLogos)) {
        echo PHP_EOL . "Missing Business Logos" . PHP_EOL;
        foreach ($missingLogos as $item) {
            $prefix = ($item['business_id'] > 0)
                ? "business_id={$item['business_id']}, "
                : '';
            echo " - {$prefix}logo={$item['logo_filename']}" . PHP_EOL;
        }
    }
}

if ($emitFixSql) {
    echo PHP_EOL . "-- Recovery SQL (if files are truly unavailable)" . PHP_EOL;

    if (!empty($missingProducts)) {
        $names = array_values(array_unique(array_map(
            static fn(array $item): string => escape_sql_literal((string)$item['image']),
            $missingProducts
        )));
        echo "UPDATE products SET image = '' WHERE image IN (" . implode(', ', $names) . ");" . PHP_EOL;
    }

    if (!empty($missingLogos)) {
        $names = array_values(array_unique(array_map(
            static fn(array $item): string => escape_sql_literal((string)$item['logo_filename']),
            $missingLogos
        )));
        echo "UPDATE business_settings SET logo_filename = '' WHERE logo_filename IN (" . implode(', ', $names) . ");" . PHP_EOL;
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

<?php

declare(strict_types=1);

/**
 * Per-arch helper-unit object sha256 ledger (#36399).
 *
 * Fingerprints prove source freshness; UNITS_SHA256SUMS proves committed
 * unit.o / unit.bc bytes match what the ledger claims (artifact-honesty).
 */

function helper_runtime_units_sha256sums_path(string $archDir): string
{
    return rtrim($archDir, '/').'/UNITS_SHA256SUMS';
}

/**
 * @return list<string> relative paths under the arch dir (units/<slug>/unit.o, …)
 */
function helper_runtime_unit_checksum_targets(string $archDir): array
{
    $unitsDir = rtrim($archDir, '/').'/units';
    if (!is_dir($unitsDir)) {
        return [];
    }
    $targets = [];
    foreach (glob($unitsDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        foreach (['unit.o', 'unit.bc'] as $name) {
            $abs = $dir.'/'.$name;
            if (is_file($abs)) {
                $targets[] = 'units/'.$slug.'/'.$name;
            }
        }
    }
    sort($targets, SORT_STRING);

    return $targets;
}

/**
 * Rewrite UNITS_SHA256SUMS from on-disk unit objects (GNU sha256sum format).
 *
 * @return int number of hashed files
 */
function helper_runtime_write_units_sha256sums(string $archDir): int
{
    $archDir = rtrim($archDir, '/');
    $targets = helper_runtime_unit_checksum_targets($archDir);
    $path = helper_runtime_units_sha256sums_path($archDir);
    $fh = fopen($path, 'wb');
    if (false === $fh) {
        throw new \RuntimeException('cannot write '.$path);
    }
    foreach ($targets as $rel) {
        $abs = $archDir.'/'.$rel;
        $hash = hash_file('sha256', $abs);
        if (!is_string($hash)) {
            fclose($fh);
            throw new \RuntimeException('cannot hash '.$abs);
        }
        fwrite($fh, $hash.'  '.$rel."\n");
    }
    fclose($fh);

    return \count($targets);
}

/**
 * Verify UNITS_SHA256SUMS against on-disk bytes.
 *
 * @return list<string> hard errors (empty = OK). Missing ledger is not an error
 *                      unless $requireLedger is true.
 */
function helper_runtime_verify_units_sha256sums(string $archDir, bool $requireLedger = false): array
{
    $archDir = rtrim($archDir, '/');
    $path = helper_runtime_units_sha256sums_path($archDir);
    if (!is_readable($path)) {
        return $requireLedger
            ? ['missing '.$path.' — run php script/write-helper-runtime-unit-checksums.php']
            : [];
    }

    $errors = [];
    $seen = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (false === $lines) {
        return ['cannot read '.$path];
    }
    foreach ($lines as $lineno => $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $m)) {
            $errors[] = $path.':'.($lineno + 1).': malformed line';
            continue;
        }
        $want = $m[1];
        $rel = $m[2];
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
            $errors[] = "{$rel}: refused path in ledger";
            continue;
        }
        $seen[$rel] = true;
        $abs = $archDir.'/'.$rel;
        if (!is_file($abs)) {
            $errors[] = "{$rel}: missing on disk (listed in UNITS_SHA256SUMS)";
            continue;
        }
        $have = hash_file('sha256', $abs);
        if (!is_string($have) || !hash_equals($want, $have)) {
            $errors[] = "{$rel}: sha256 mismatch (ledger {$want}, on-disk ".($have ?: '?').')';
        }
    }

    // Extra unit files not in the ledger are broken once a ledger exists.
    foreach (helper_runtime_unit_checksum_targets($archDir) as $rel) {
        if (!isset($seen[$rel])) {
            $errors[] = "{$rel}: on disk but missing from UNITS_SHA256SUMS — refresh ledger (#36399)";
        }
    }

    return $errors;
}

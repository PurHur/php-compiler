<?php

declare(strict_types=1);

/**
 * Repro #15383 — fputcsv() escape '\\' must not double backslashes (ext/standard/file.c).
 */
$fp = fopen('php://memory', 'r+');
if (false === $fp) {
    fwrite(STDERR, "fail: fopen\n");
    exit(1);
}
$written = fputcsv($fp, ['a\b'], ',', '"', '\\');
if (false === $written) {
    fwrite(STDERR, "fail: fputcsv returned false\n");
    exit(1);
}
rewind($fp);
$line = stream_get_contents($fp);
fclose($fp);

$expected = "\"a\\b\"\n";
if ($line !== $expected) {
    fwrite(STDERR, 'fail: got '.json_encode($line).' expected '.json_encode($expected)."\n");
    exit(1);
}

$fp = fopen('php://memory', 'r+');
fputcsv($fp, ['a\b'], ',', '"', '\\');
rewind($fp);
$row = fgetcsv($fp);
fclose($fp);
if (!is_array($row) || ($row[0] ?? null) !== 'a\b') {
    fwrite(STDERR, 'fail: roundtrip got '.json_encode($row)."\n");
    exit(1);
}

echo "ok\n";

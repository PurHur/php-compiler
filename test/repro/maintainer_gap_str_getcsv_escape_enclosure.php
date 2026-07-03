<?php

declare(strict_types=1);

/**
 * Issue #9303 — str_getcsv()/fgetcsv() when escape equals enclosure (ext/standard/file.c).
 */

$line = 'a,"b""c",d';
$row = str_getcsv($line, ',', '"', '"');
$expected = ['a', 'b"c', 'd'];
if ($row !== $expected) {
    echo 'fail: str_getcsv got ', var_export($row, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

$f = fopen('php://memory', 'r+');
fwrite($f, $line."\n");
rewind($f);
$frow = fgetcsv($f, 0, ',', '"', '"');
fclose($f);
if ($frow !== $expected) {
    echo 'fail: fgetcsv got ', var_export($frow, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

echo "ok\n";

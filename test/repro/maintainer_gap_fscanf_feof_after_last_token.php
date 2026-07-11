<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
fwrite($h, 'hello');
rewind($h);
$r = fscanf($h, '%s');
if (!\is_array($r) || 'hello' !== ($r[0] ?? null)) {
    echo "fail: fscanf returned ", var_export($r, true), "\n";
    exit(1);
}
if (!feof($h)) {
    echo "fail: feof() false after fscanf consumed sole token\n";
    exit(1);
}
fclose($h);
echo "ok\n";

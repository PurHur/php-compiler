<?php

declare(strict_types=1);

// Zend parity: fscanf() on non-seekable stdin (#15992, ext/standard/fscanf.c).
$fp = fopen('php://stdin', 'r');
if (false === $fp) {
    echo "fail: fopen\n";
    exit(1);
}
$result = fscanf($fp, '%d');
if (!\is_array($result) || !isset($result[0]) || 99 !== $result[0]) {
    echo 'fail: fscanf stdin: ', var_export($result, true), "\n";
    exit(1);
}
fclose($fp);
echo "ok\n";

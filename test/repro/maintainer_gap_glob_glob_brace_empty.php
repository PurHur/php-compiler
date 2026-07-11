<?php
declare(strict_types=1);

$result = glob('{a,b}.txt', GLOB_BRACE);
if (false === $result) {
    echo "fail: glob('{a,b}.txt', GLOB_BRACE) returned false — Zend returns empty array\n";
    exit(1);
}
if ([] !== $result) {
    echo "fail: expected empty array, got ", var_export($result, true), "\n";
    exit(1);
}
echo "ok\n";

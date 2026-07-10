<?php
declare(strict_types=1);
$overflow = (string) PHP_INT_MAX . '0';
$r = filter_var($overflow, FILTER_VALIDATE_INT);
if ($r !== false) {
    echo "bad overflow\n";
    exit(1);
}
$r = filter_var('0123', FILTER_VALIDATE_INT);
if ($r !== false) {
    echo "bad leading zero\n";
    exit(1);
}
if (filter_var((string) PHP_INT_MAX, FILTER_VALIDATE_INT) !== PHP_INT_MAX) {
    echo "bad max\n";
    exit(1);
}
if (filter_var((string) PHP_INT_MIN, FILTER_VALIDATE_INT) !== PHP_INT_MIN) {
    echo "bad min\n";
    exit(1);
}
echo "ok\n";

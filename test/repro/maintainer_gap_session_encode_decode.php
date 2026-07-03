<?php

declare(strict_types=1);

session_start();
$_SESSION['k'] = 'v';
$blob = session_encode();
session_unset();
if (!session_decode($blob)) {
    echo "fail: session_decode returned false\n";
    exit(1);
}
if (!isset($_SESSION['k']) || 'v' !== $_SESSION['k']) {
    $val = $_SESSION['k'] ?? null;
    echo 'fail: expected v got ', \var_export($val, true), "\n";
    exit(1);
}
if ('k|s:1:"v";' !== $blob) {
    echo 'fail: unexpected blob ', $blob, "\n";
    exit(1);
}
echo "ok\n";

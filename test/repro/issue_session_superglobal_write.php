<?php

declare(strict_types=1);

session_start();
$_SESSION['k'] = 'v';
if ('v' !== ($_SESSION['k'] ?? null)) {
    echo 'fail: same-request read got ', var_export($_SESSION['k'] ?? null, true), "\n";
    exit(1);
}
session_write_close();
session_start();
$got = $_SESSION['k'] ?? null;
echo 'session_get: ', var_export($got, true), "\n";
if ('v' !== $got) {
    exit(1);
}

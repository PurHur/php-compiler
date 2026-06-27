<?php

declare(strict_types=1);

if (!function_exists('session_commit')) {
    fwrite(STDERR, "fail: session_commit() not registered\n");
    exit(1);
}
if (!function_exists('session_write_close')) {
    fwrite(STDERR, "fail: session_write_close() not registered\n");
    exit(1);
}

session_start();
$_SESSION['probe'] = 'ok';
session_commit();

if (!isset($_SESSION['probe']) || 'ok' !== $_SESSION['probe']) {
    fwrite(STDERR, "fail: session data lost after session_commit()\n");
    exit(1);
}

echo "ok\n";

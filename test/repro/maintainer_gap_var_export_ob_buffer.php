<?php

declare(strict_types=1);

/**
 * Repro #11649 — var_export() echo mode must respect ob_start() (ext/standard/var.c).
 *
 * php-src: ext/standard/var.c — php_var_export_ex routes through PHP output layer.
 */

$u = posix_uname();
ob_start();
var_export($u['domainname']);
$captured = ob_get_clean();

if ('\'(none)\'' !== $captured) {
    echo 'fail: captured=', var_export($captured, true), "\n";
    exit(1);
}

echo "ok\n";

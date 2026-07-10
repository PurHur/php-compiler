<?php

declare(strict_types=1);

if (!function_exists('mb_ucfirst') || !function_exists('mb_lcfirst')) {
    echo "fail: mb_ucfirst/mb_lcfirst not registered\n";
    exit(1);
}

if (!function_exists('mb_ucfirst') || !function_exists('mb_lcfirst')) {
    echo "fail: introspection false while callable\n";
    exit(1);
}

$uc = mb_ucfirst('über');
if ('Über' !== $uc) {
    echo "fail: mb_ucfirst got ", var_export($uc, true), "\n";
    exit(1);
}

$lc = mb_lcfirst('Über');
if ('über' !== $lc) {
    echo "fail: mb_lcfirst got ", var_export($lc, true), "\n";
    exit(1);
}

echo "ok\n";

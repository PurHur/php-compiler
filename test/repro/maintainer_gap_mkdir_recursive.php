<?php

declare(strict_types=1);

$base = sys_get_temp_dir().'/phpc_mk_'.uniqid('', true);
$nested = $base.'/a/b';
@rmdir($nested);
@rmdir($base.'/a');
@rmdir($base);

if (!mkdir($nested, 0777, true)) {
    echo "fail: mkdir recursive returned false\n";
    exit(1);
}
if (!is_dir($nested)) {
    echo "fail: nested directory missing after mkdir\n";
    exit(1);
}

@rmdir($nested);
@rmdir($base.'/a');
@rmdir($base);

echo "ok\n";

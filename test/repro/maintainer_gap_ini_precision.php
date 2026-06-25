<?php

declare(strict_types=1);

/**
 * Maintainer repro: precision INI round-trip (issue #11841, ext/standard/ini.c).
 */

$default = ini_get('precision');
if ('14' !== $default) {
    echo 'fail: ini_get(precision)='.$default."\n";
    exit(1);
}

$old = ini_set('precision', '8');
if ('14' !== $old) {
    echo 'fail: ini_set old='.$old."\n";
    exit(1);
}

$afterSet = ini_get('precision');
if ('8' !== $afterSet) {
    echo 'fail: ini_get(precision) after_set='.$afterSet."\n";
    exit(1);
}

ini_restore('precision');
$afterRestore = ini_get('precision');
if ('14' !== $afterRestore) {
    echo 'fail: ini_get(precision) after_restore='.$afterRestore."\n";
    exit(1);
}

echo "ok\n";

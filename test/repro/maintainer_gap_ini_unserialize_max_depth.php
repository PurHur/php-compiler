<?php

declare(strict_types=1);

$depth = ini_get('unserialize_max_depth');
if ('4096' !== $depth) {
    fwrite(STDERR, "FAIL: expected default '4096', got ".var_export($depth, true)."\n");
    exit(1);
}

$old = ini_set('unserialize_max_depth', '8');
if ('4096' !== $old) {
    fwrite(STDERR, "FAIL: ini_set old value expected '4096', got ".var_export($old, true)."\n");
    exit(1);
}
if ('8' !== ini_get('unserialize_max_depth')) {
    fwrite(STDERR, "FAIL: ini_set round-trip expected '8', got ".var_export(ini_get('unserialize_max_depth'), true)."\n");
    exit(1);
}

echo "ok\n";

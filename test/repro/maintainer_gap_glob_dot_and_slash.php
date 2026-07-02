<?php

declare(strict_types=1);

$dot = glob('.');
if (['.'] !== $dot) {
    echo 'fail: glob(".") got '.var_export($dot, true)."\n";
    exit(1);
}

$tmp = sys_get_temp_dir();
$abs = glob($tmp);
if (!is_array($abs) || 1 !== count($abs)) {
    echo 'fail: glob(temp_dir) expected single match, got '.var_export($abs, true)."\n";
    exit(1);
}
if ($abs[0] !== $tmp) {
    echo 'fail: glob(temp_dir) path '.var_export($abs[0], true).' expected '.var_export($tmp, true)."\n";
    exit(1);
}
if (str_starts_with($abs[0], '//')) {
    echo 'fail: glob(temp_dir) path has double leading slash: '.$abs[0]."\n";
    exit(1);
}

echo "ok\n";

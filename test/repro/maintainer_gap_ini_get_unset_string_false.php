<?php

declare(strict_types=1);

$fail = false;

foreach (['browscap', 'error_log', 'doc_root'] as $key) {
    $v = ini_get($key);
    if ('string' !== gettype($v) || '' !== $v) {
        echo "fail: ini_get({$key}) expected empty string, got ".var_export($v, true)."\n";
        $fail = true;
    }
}

$hostExt = \function_exists('ini_get') ? @\ini_get('extension_dir') : false;
$vmExt = ini_get('extension_dir');
if (\is_string($hostExt) && $hostExt !== $vmExt) {
    echo 'fail: ini_get(extension_dir) expected '.var_export($hostExt, true).', got '.var_export($vmExt, true)."\n";
    $fail = true;
} elseif (false === $hostExt && false !== $vmExt) {
    echo 'fail: ini_get(extension_dir) expected false, got '.var_export($vmExt, true)."\n";
    $fail = true;
}

if (false !== ini_get('bogus_xyz_123')) {
    echo "fail: unknown ini key should be false\n";
    $fail = true;
}

if ($fail) {
    exit(1);
}

echo "ok\n";

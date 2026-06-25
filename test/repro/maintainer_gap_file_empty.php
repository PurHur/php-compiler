<?php

declare(strict_types=1);

/** Issue #11710 — file() on zero-byte file returns [] (ext/standard/file.c). */
$path = sys_get_temp_dir().'/phpc_file_empty_'.getmypid().'.txt';
file_put_contents($path, '');
$lines = file($path);
echo 'count=', count($lines), "\n";
echo 'first=', var_export($lines[0] ?? null, true), "\n";
unlink($path);

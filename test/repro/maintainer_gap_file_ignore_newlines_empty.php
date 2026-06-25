<?php

declare(strict_types=1);

/** Issue #11710 — file(FILE_IGNORE_NEW_LINES) on zero-byte file returns [] (ext/standard/file.c). */
$path = sys_get_temp_dir().'/phpc_file_ignore_nl_empty_'.getmypid().'.txt';
file_put_contents($path, '');
$lines = file($path, FILE_IGNORE_NEW_LINES);
echo 'count=', count($lines), "\n";
unlink($path);

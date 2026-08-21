<?php

declare(strict_types=1);

/**
 * Repro #33426 — thin AOT fclose must fflush via fclose(3); clear-only dropped fwrite buffer.
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fclose) / php_stream_close
 */
$path = sys_get_temp_dir() . '/fclose_fwrite_aot_' . getmypid() . '.txt';
@unlink($path);

$f = fopen($path, 'w');
$n = fwrite($f, 'hi');
$closed = fclose($f);
$contents = @file_get_contents($path);
$size = @filesize($path);

echo 'n=', var_export($n, true), "\n";
echo 'closed=', var_export($closed, true), "\n";
echo 'contents=', var_export($contents, true), "\n";
echo 'size=', var_export($size, true), "\n";

@unlink($path);

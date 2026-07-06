<?php

declare(strict_types=1);

/**
 * Repro #17024 — file_put_contents($path, null) must write 0 bytes and return 0 (php-src Z_PARAM_STR).
 */

$tmp = tempnam(sys_get_temp_dir(), 'phpc-fpc-null-');
if (false === $tmp) {
    fwrite(STDERR, "tempnam failed\n");
    exit(1);
}

$n = file_put_contents($tmp, null);
$body = file_get_contents($tmp);
@unlink($tmp);

var_export($n);
echo "\n";
var_export($body);
echo "\n";

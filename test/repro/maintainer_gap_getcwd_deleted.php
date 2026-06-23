<?php

declare(strict_types=1);

// Maintainer gap / issue #10451 — getcwd() after rmdir(cwd) must be false (ext/standard/dir.c).
$path = '/tmp/phpc_getcwd_deleted_probe';
@mkdir($path);
chdir($path);
@rmdir($path);
$r = getcwd();
if (false !== $r) {
    fwrite(STDERR, 'expected false, got '.var_export($r, true)."\n");
    exit(1);
}
echo "PASS\n";

<?php

declare(strict_types=1);

/**
 * #23232 — PHP 8.5 max_memory_limit INI default + INI_SYSTEM (#23232).
 *
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/maintainer_gap_max_memory_limit_ini_85.php
 */

$v = ini_get('max_memory_limit');
if (!is_string($v) || '-1' !== $v) {
    fwrite(STDERR, 'fail: expected max_memory_limit="-1", got '.var_export($v, true)."\n");
    exit(1);
}
if (false !== ini_set('max_memory_limit', '128M')) {
    fwrite(STDERR, "fail: ini_set(max_memory_limit) must return false (INI_SYSTEM)\n");
    exit(1);
}
if ('-1' !== ini_get('max_memory_limit')) {
    fwrite(STDERR, "fail: max_memory_limit mutated by rejected ini_set\n");
    exit(1);
}
echo "default-ok\n";

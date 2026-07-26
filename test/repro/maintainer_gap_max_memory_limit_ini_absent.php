<?php

declare(strict_types=1);

/**
 * #23232 — max_memory_limit absent on PROFILE&lt;8.5 (php-src-strict).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_max_memory_limit_ini_absent.php
 *   php bin/vm.php test/repro/maintainer_gap_max_memory_limit_ini_absent.php
 */

$v = ini_get('max_memory_limit');
if (false !== $v) {
    fwrite(STDERR, 'fail: max_memory_limit must be unknown, got '.var_export($v, true)."\n");
    exit(1);
}
echo "absent-ok\n";

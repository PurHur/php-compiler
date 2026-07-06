<?php

declare(strict_types=1);

/**
 * Maintainer repro: NoDiscard must not exist on 8.4.0-dev reference profile (#13706).
 * Run with profile cleared: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_nodiscard_registration.php
 */

if (class_exists('NoDiscard', false)) {
    echo "fail: NoDiscard phantom class on 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";

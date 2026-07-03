<?php

declare(strict_types=1);

/**
 * Maintainer repro: zero-arg array-return builtin nested in call argument (#15438).
 */

echo var_export(sys_getloadavg(), true), "\n";

$a = sys_getloadavg();
echo var_export($a, true), "\n";

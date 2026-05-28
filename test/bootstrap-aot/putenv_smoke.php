<?php

declare(strict_types=1);

/**
 * putenv/getenv smoke for self-host compile drivers.
 *
 * This is a bootstrap AOT fixture (not a full PHP compatibility test).
 */

if (!\function_exists('putenv') || !\function_exists('getenv')) {
    echo "missing env builtins\n";
    exit(1);
}

$ok = \putenv('PHP_COMPILER_PUTENV_SMOKE=hello');
if (!$ok) {
    echo "putenv failed\n";
    exit(2);
}

echo "putenv ok\n";


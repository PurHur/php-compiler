<?php

declare(strict_types=1);

/**
 * Issue #21379 / re-#20100 — number_format(null) on PHP_COMPILER_PROFILE=8.4.
 */

try {
    number_format(null);
    echo "COERCE\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}

<?php

declare(strict_types=1);

/**
 * Issue #21380 / re-#20113 — parse_str(null) on PHP_COMPILER_PROFILE=8.4.
 */

try {
    parse_str(null, $o);
    echo "COERCE\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}

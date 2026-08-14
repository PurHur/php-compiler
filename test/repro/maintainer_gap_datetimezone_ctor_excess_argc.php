<?php

declare(strict_types=1);

/**
 * Repro #31068 — DateTimeZone::__construct() excess argc (ext/date/php_date.c).
 *
 * Zend: ArgumentCountError "expects exactly 1 argument, 2 given"
 * VM (pre-fix): silently constructs UTC zone
 */
try {
    new DateTimeZone('UTC', 1);
    echo "ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

--TEST--
stdlib microtime(null) under strict_types throws TypeError (php-src Z_PARAM_BOOL; maintainer_gap_date_time_null_operands)
--FILE--
<?php
declare(strict_types=1);
try {
    microtime(null);
    echo "no throw\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
TypeError

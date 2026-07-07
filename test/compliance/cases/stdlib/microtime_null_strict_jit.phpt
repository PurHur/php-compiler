--TEST--
stdlib microtime(null) strict_types JIT throws TypeError
--JIT--
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

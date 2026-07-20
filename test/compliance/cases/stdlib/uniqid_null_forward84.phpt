--TEST--
stdlib uniqid(null) soft-null on 8.4 (#21280, reverts #20138)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    $r = uniqid(null);
    echo is_string($r) && strlen($r) >= 13 ? "COERCE\n" : "BAD\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
COERCE

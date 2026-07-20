--TEST--
AOT: parse_str(null) — soft-null coerce on 8.4 (#21480, reverts #21380)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
try {
    parse_str(null, $o);
    echo "COERCE\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
?>
--EXPECT--
COERCE

--TEST--
AOT: parse_str(null) — TypeError on 8.4 forward profile (#21380, re-#20113)
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
TypeError

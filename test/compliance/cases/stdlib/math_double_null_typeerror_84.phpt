--TEST--
stdlib nextafter(1.0, null) TypeError on 8.4 forward profile (#19182, #20432)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    nextafter(1.0, null);
    echo "fail nextafter\n";
} catch (TypeError $e) {
    echo "ok nextafter\n";
}
--EXPECT--
ok nextafter

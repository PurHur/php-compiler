--TEST--
stdlib fdiv/fmod/hypot/atan2(null) TypeError on 8.4 forward profile (#19182, #20432, #24198)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['fdiv', 'fmod', 'hypot', 'atan2'] as $fn) {
    try {
        $fn(null, 1.0);
        echo "fail {$fn}\n";
    } catch (TypeError $e) {
        echo "ok {$fn}\n";
    }
}
--EXPECT--
ok fdiv
ok fmod
ok hypot
ok atan2

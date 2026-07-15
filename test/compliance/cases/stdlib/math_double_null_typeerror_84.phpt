--TEST--
stdlib fadd/fsub/fmul/fdiv/fmod/fpow/hypot/atan2/nextafter(null) TypeError on 8.4 forward profile (#19182)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['fadd', 'fsub', 'fmul', 'fdiv', 'fmod', 'fpow', 'hypot', 'atan2'] as $fn) {
    try {
        $fn(null, 1.0);
        echo "fail {$fn}\n";
    } catch (TypeError $e) {
        echo "ok {$fn}\n";
    }
}
try {
    nextafter(1.0, null);
    echo "fail nextafter\n";
} catch (TypeError $e) {
    echo "ok nextafter\n";
}
--EXPECT--
ok fadd
ok fsub
ok fmul
ok fdiv
ok fmod
ok fpow
ok hypot
ok atan2
ok nextafter

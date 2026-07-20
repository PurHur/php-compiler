--TEST--
stdlib fadd/fsub/fmul/fpow(null) TypeError on 8.4 forward profile (#19182, #20432)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['fadd', 'fsub', 'fmul', 'fpow'] as $fn) {
    try {
        $fn(null, 1.0);
        echo "fail {$fn}\n";
    } catch (TypeError $e) {
        echo "ok {$fn}\n";
    }
}
--EXPECT--
ok fadd
ok fsub
ok fmul
ok fpow

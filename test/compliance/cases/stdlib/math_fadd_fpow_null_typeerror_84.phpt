--TEST--
stdlib fadd/fsub/fmul(null) TypeError on 8.4 forward profile (#19182, #20432; fpow → #24177)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['fadd', 'fsub', 'fmul'] as $fn) {
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

--TEST--
stdlib abs()/round()/ceil()/floor(null) TypeError on 8.4 forward profile (#18924)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['abs', 'round', 'ceil', 'floor'] as $fn) {
    try {
        $fn(null);
        echo "fail {$fn}\n";
    } catch (TypeError $e) {
        echo "ok {$fn}\n";
    }
}
--EXPECT--
ok abs
ok round
ok ceil
ok floor

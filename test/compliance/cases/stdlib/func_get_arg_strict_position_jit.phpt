--TEST--
stdlib func_get_arg() string $position TypeError under strict_types JIT (issue #12337)
--JIT--
--FILE--
<?php
declare(strict_types=1);
function f($a, $b) {
    try {
        func_get_arg('0');
        echo "no_error\n";
    } catch (TypeError $e) {
        echo str_contains($e->getMessage(), 'int') ? "type_error\n" : "bad_msg\n";
    }
    echo func_get_arg(0), "\n";
}
f(10, 20);
--EXPECT--
type_error
10

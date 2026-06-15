--TEST--
stdlib sleep()/usleep() JIT — numeric-string and float seconds coercion (#4323)
--FILE--
<?php
echo sleep("0"), "\n";
var_export(usleep("0"));
echo "\n";
echo sleep(0.9), "\n";
try {
    sleep("x");
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
0
NULL
0
sleep(): Argument #1 ($seconds) must be of type int, string given

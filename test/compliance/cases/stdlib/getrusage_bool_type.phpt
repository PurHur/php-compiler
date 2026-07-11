--TEST--
stdlib getrusage() — bool $mode TypeError (#11686, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    getrusage(true);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    getrusage(false);
    echo "no-error-false\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$u = getrusage(0);
echo is_array($u) ? "usage-ok\n" : "usage-fail\n";
--EXPECT--
getrusage(): Argument #1 ($mode) must be of type int, bool given
getrusage(): Argument #1 ($mode) must be of type int, bool given
usage-ok

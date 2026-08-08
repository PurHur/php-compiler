--TEST--
stdlib getrusage() JIT — bool $mode TypeError (#11686)
--FILE--
<?php
try {
    getrusage(true);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
getrusage(): Argument #1 ($mode) must be of type int, true given

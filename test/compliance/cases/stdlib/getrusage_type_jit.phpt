--TEST--
stdlib getrusage() JIT — numeric-string coercion + array TypeError (#4600)
--FILE--
<?php
var_dump(getrusage("0") !== false);
try {
    getrusage([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
bool(true)
getrusage(): Argument #1 ($mode) must be of type int, array given

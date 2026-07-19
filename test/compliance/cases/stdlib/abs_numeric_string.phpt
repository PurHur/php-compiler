--TEST--
stdlib abs() — numeric-string coerce without strict_types; TypeError with strict_types (#4189, ext/standard/math.c)
--FILE--
<?php
echo abs("5"), "\n";
echo abs("-3.5"), "\n";
try {
    abs([]);
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
5
3.5
TypeError

--TEST--
stdlib round() precision strict_types — JIT (issue #9482)
--FILE--
<?php
declare(strict_types=1);
try {
    round(1.5, 0.9);
    echo "no error float\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    round(1.5, '1');
    echo "no error string\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    round(1.5, 1.9);
    echo "no error float2\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo round(1.5, 2), "\n";
--EXPECT--
round(): Argument #2 ($precision) must be of type int, float given
round(): Argument #2 ($precision) must be of type int, string given
round(): Argument #2 ($precision) must be of type int, float given
1.5

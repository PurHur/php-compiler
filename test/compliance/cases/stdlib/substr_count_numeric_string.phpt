--TEST--
stdlib substr_count() — numeric-string offset/length (#4259)
--FILE--
<?php
echo substr_count('abababa', 'ab', '2', '4'), "\n";
try {
    $n = substr_count('abababa', 'ab', 'abc', '4');
    echo $n, "\n";
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
2
TypeError
substr_count(): Argument #3 ($offset) must be of type int, string given

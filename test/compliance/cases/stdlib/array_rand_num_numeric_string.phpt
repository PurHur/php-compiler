--TEST--
stdlib array_rand() — numeric-string $num coercion (#4320, ext/standard/array.c)
--FILE--
<?php
$keys = ['a', 'b', 'c'];
$k = array_rand($keys, '1');
$ok = is_int($k) && $k >= 0 && $k <= 2;
echo $ok ? "ok\n" : "bad\n";
try {
    array_rand($keys, 'nope');
    echo "non_numeric: uncaught\n";
} catch (TypeError $e) {
    echo 'non_numeric: ', $e->getMessage(), "\n";
}
--EXPECT--
ok
non_numeric: array_rand(): Argument #2 ($num) must be of type int, string given

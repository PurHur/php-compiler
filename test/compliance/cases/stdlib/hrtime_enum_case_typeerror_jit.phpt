--TEST--
stdlib hrtime() JIT — enum case $as_number TypeError (#8815, ext/standard/hrtime.c)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    hrtime(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$n = hrtime(true);
echo (is_int($n) || is_float($n)) ? "num\n" : "bad\n";
echo is_array(hrtime(false)) ? "false_pair\n" : "bad\n";
echo is_array(hrtime()) ? "pair\n" : "bad\n";
?>
--EXPECT--
hrtime(): Argument #1 ($as_number) must be of type bool, E given
num
false_pair
pair

--TEST--
stdlib ignore_user_abort() — int operand TypeError (?bool, #12585)
--FILE--
<?php
try {
    ignore_user_abort(0);
    echo "accepted\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo ignore_user_abort(false), "\n";
echo ignore_user_abort(null), "\n";
echo "ok\n";
?>
--EXPECT--
ignore_user_abort(): Argument #1 ($value) must be of type bool, int given
0
0
ok

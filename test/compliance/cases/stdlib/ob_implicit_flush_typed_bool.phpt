--TEST--
stdlib ob_implicit_flush() — int operand TypeError (bool, #12586)
--FILE--
<?php
try {
    ob_implicit_flush(0);
    echo "accepted\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
var_dump(ob_implicit_flush(false));
var_dump(ob_implicit_flush());
echo "ok\n";
?>
--EXPECT--
ob_implicit_flush(): Argument #1 ($enable) must be of type bool, int given
NULL
NULL
ok

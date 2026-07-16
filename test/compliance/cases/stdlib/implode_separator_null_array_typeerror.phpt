--TEST--
stdlib implode()/join(separator, null) — Arg #1 ($array) string given (#19566, ext/standard/string.c)
--FILE--
<?php
try {
    implode(",", null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    join(",", null);
    echo "uncaught join\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo implode(["a", "b"], null), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo implode(",", ["a", "b"]), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
implode(): Argument #1 ($array) must be of type array, string given
join(): Argument #1 ($array) must be of type array, string given
ab
a,b

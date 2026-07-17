--TEST--
stdlib inflate_add()/deflate_add() too-few-args ArgumentCountError (#19916, ext/zlib/zlib.c)
--FILE--
<?php
try {
    inflate_add(null);
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    deflate_add(null);
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
inflate_add() expects at least 2 arguments, 1 given
deflate_add() expects at least 2 arguments, 1 given

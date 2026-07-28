--TEST--
JIT: stdlib str_increment()/str_decrement(null) ValueError on 8.4 (#24179, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!function_exists('str_increment')) {
    die('skip str_increment not supported');
}
?>
--FILE--
<?php
foreach (['str_increment', 'str_decrement'] as $f) {
    try {
        $f(null);
        echo $f, " COERCED\n";
    } catch (TypeError $e) {
        echo $f, " TypeError\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
try {
    str_increment('');
    echo "empty COERCED\n";
} catch (ValueError $e) {
    echo "empty ValueError\n";
}
--EXPECT--
str_increment ValueError
str_decrement ValueError
empty ValueError

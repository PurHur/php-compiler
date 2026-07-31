--TEST--
ext/mysqli mysqli_dump_debug_info / mysqli_debug (#22223, php-src mysqli.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
declare(strict_types=1);

echo function_exists('mysqli_dump_debug_info') ? "dump=yes\n" : "dump=no\n";
echo function_exists('mysqli_debug') ? "debug=yes\n" : "debug=no\n";
echo method_exists('mysqli', 'dump_debug_info') ? "method=yes\n" : "method=no\n";

try {
    mysqli_dump_debug_info();
    echo "arity_dump=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_dump=yes\n";
}
try {
    mysqli_dump_debug_info(false);
    echo "type_dump=no\n";
} catch (TypeError $e) {
    echo "type_dump=yes\n";
}
try {
    mysqli_debug();
    echo "arity_debug=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_debug=yes\n";
}

$ok = mysqli_debug('d:t:o,/dev/null');
echo 'debug_ret=', ($ok === true) ? "true\n" : "other\n";
?>
--EXPECT--
dump=yes
debug=yes
method=yes
arity_dump=yes
type_dump=yes
arity_debug=yes
debug_ret=true

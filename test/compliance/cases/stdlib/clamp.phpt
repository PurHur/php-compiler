--TEST--
stdlib clamp() (#17336, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo clamp(5, 1, 3), "\n";
echo clamp(0, 1, 3), "\n";
echo clamp(2, 1, 3), "\n";
echo clamp(1.5, 1.0, 3.0), "\n";
try {
    clamp(1, 3, 2);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
3
1
2
1.5
clamp(): Argument #2 ($min) must be smaller than or equal to argument #3 ($max)

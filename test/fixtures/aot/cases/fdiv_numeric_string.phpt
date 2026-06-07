--TEST--
AOT: fdiv() numeric-string coercion (#4388)
--FILE--
<?php
echo fdiv("6", "2"), "\n";
try {
    fdiv([], 2);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
3
fdiv(): Argument #1 ($num1) must be of type float, array given
--EXPECT_EXIT--
0

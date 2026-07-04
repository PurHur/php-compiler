--TEST--
stdlib array_map() null zip — inline null haystack TypeError (re-#9143, #16226)
--FILE--
<?php
try {
    array_map(null, null, [1, 2]);
    echo "unexpected success\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_map(): Argument #2 ($array) must be of type array, null given

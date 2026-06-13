--TEST--
stdlib round() accepts out-of-range mode integers like Zend 8.2 (#4509)
--FILE--
<?php
var_export(round(2.5, 0, 99));
echo "\n";
try {
    round(1.0, 0, 99);
    echo "no_throw\n";
} catch (ValueError $e) {
    echo "caught\n";
}
--EXPECT--
3.0
no_throw
--EXPECT_EXIT--
0

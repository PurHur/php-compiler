--TEST--
language: array literal spread of null is uncatchable fatal (zend_execute.c, #4686)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export([...null]);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECTF--
Fatal error: Only arrays and Traversables can be unpacked in %s on line %d
--EXPECT_EXIT--
255

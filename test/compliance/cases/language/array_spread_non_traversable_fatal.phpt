--TEST--
language: array literal spread of non-traversable string is uncatchable fatal Error (zend_execute.c, #4812)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export([...[1, 2], ...'ab']);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
?>
--EXPECTF--
Fatal error: Only arrays and Traversables can be unpacked in %s on line %d
--EXPECT_EXIT--
255

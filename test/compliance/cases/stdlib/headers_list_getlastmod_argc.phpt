--TEST--
stdlib headers_list/getlastmod excess argc → ArgumentCountError (#30417)
--FILE--
<?php
foreach (['headers_list', 'getlastmod'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
headers_list() expects exactly 0 arguments, 1 given
getlastmod() expects exactly 0 arguments, 1 given

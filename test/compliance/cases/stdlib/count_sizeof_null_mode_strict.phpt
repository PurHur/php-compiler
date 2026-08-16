--TEST--
stdlib count/sizeof(null $mode) TypeError under strict_types (#31463)
--FILE--
<?php
declare(strict_types=1);
foreach (['count', 'sizeof'] as $fn) {
    try {
        $fn([1, 2], null);
        echo "$fn: fail\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
count(): Argument #2 ($mode) must be of type int, null given
sizeof(): Argument #2 ($mode) must be of type int, null given

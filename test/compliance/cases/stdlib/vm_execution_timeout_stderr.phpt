--TEST--
VM: max_execution_time exceeded fatal reaches stderr when display_errors=0 (#15906)
--FILE--
<?php
declare(strict_types=1);
ini_set('display_errors', '0');
set_time_limit(1);
$i = 0;
while (true) {
    ++$i;
}
--EXPECTF--
PHP Fatal error:  Maximum execution time of 1 second exceeded in %s on line %d
--EXPECT_EXIT--
255

--TEST--
stdlib set_time_limit() enforces execution limit (#3242)
--FILE--
<?php
set_time_limit(1);
$i = 0;
while (true) {
    ++$i;
}
--EXPECTF--
PHP Fatal error:  Maximum execution time of 1 second exceeded in %s on line %d
--EXPECT_EXIT--
255

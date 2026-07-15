--TEST--
language sleep(null) under strict_types JIT throws TypeError (#19079)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach (['sleep', 'usleep'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
sleep(): Argument #1 ($seconds) must be of type int, null given
usleep(): Argument #1 ($microseconds) must be of type int, null given

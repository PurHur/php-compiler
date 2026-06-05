--TEST--
stdlib sleep()/usleep() — enum case TypeError (#6148, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    sleep(E::A);
    echo "sleep-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    usleep(E::A);
    echo "usleep-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
sleep(): Argument #1 ($seconds) must be of type int, E given
usleep(): Argument #1 ($microseconds) must be of type int, E given

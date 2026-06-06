--TEST--
stdlib microtime()/hrtime()/gettimeofday()/time_nanosleep() — enum case TypeError (#6149, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

foreach ([
    'microtime' => static fn () => microtime(E::A),
    'hrtime' => static fn () => hrtime(E::A),
    'gettimeofday' => static fn () => gettimeofday(E::A),
    'time_nanosleep' => static fn () => time_nanosleep(E::A, 0),
] as $name => $fn) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
microtime: microtime(): Argument #1 ($as_float) must be of type bool, E given
hrtime: hrtime(): Argument #1 ($as_number) must be of type bool, E given
gettimeofday: gettimeofday(): Argument #1 ($as_float) must be of type bool, E given
time_nanosleep: time_nanosleep(): Argument #1 ($seconds) must be of type int, E given

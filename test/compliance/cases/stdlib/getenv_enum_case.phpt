--TEST--
stdlib getenv()/putenv() — enum case operands TypeError (#5813, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'PATH'; }

foreach ([
    ['getenv', fn () => getenv(E::A)],
    ['putenv', fn () => putenv(E::A)],
] as [$fn, $call]) {
    try {
        $call();
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
getenv: getenv(): Argument #1 ($name) must be of type ?string, E given
putenv: putenv(): Argument #1 ($assignment) must be of type string, E given

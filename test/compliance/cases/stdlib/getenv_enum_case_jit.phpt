--TEST--
stdlib getenv()/putenv() JIT — enum case operands TypeError (#5813)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'PATH'; }

foreach ([
    ['getenv', fn () => getenv(E::A)],
    ['putenv', fn () => putenv(E::A)],
] as [$label, $call]) {
    try {
        $call();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
getenv: getenv(): Argument #1 ($name) must be of type ?string, E given
putenv: putenv(): Argument #1 ($assignment) must be of type string, E given

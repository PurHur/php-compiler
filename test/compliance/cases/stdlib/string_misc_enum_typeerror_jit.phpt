--TEST--
stdlib str_pad/addslashes/addcslashes/nl2br/ucfirst JIT — backed enum case TypeError (#5861, #8846)
--FILE--
<?php
enum E: string { case A = "a\nb"; }

$tests = [
    ['str_pad', static fn () => str_pad(E::A, 5)],
    ['addslashes', static fn () => addslashes(E::A)],
    ['addcslashes', static fn () => addcslashes(E::A, "'")],
    ['nl2br', static fn () => nl2br(E::A)],
    ['ucfirst', static fn () => ucfirst(E::A)],
];

foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
str_pad: str_pad(): Argument #1 ($string) must be of type string, E given
addslashes: addslashes(): Argument #1 ($string) must be of type string, E given
addcslashes: addcslashes(): Argument #1 ($string) must be of type string, E given
nl2br: nl2br(): Argument #1 ($string) must be of type string, E given
ucfirst: ucfirst(): Argument #1 ($string) must be of type string, E given

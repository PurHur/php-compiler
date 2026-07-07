--TEST--
stdlib glob() — ArgumentCountError/TypeError parity (ext/standard/dir.c)
--FILE--
<?php
foreach ([
    fn() => glob(),
    fn() => glob('*.php', 0, 123),
] as $f) {
    try {
        $f();
        echo "uncaught\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}

try {
    glob('*.php', []);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
glob() expects at least 1 argument, 0 given
glob() expects at most 2 arguments, 3 given
glob(): Argument #2 ($flags) must be of type int, array given

--TEST--
stdlib getrusage() — backed enum case TypeError (#6707, ext/standard/basic_functions.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    getrusage(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$u = getrusage(0);
echo isset($u['ru_maxrss']) ? "int-ok\n" : "int-bad\n";
--EXPECT--
getrusage(): Argument #1 ($mode) must be of type int, E given
int-ok

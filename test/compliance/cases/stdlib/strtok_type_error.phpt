--TEST--
stdlib strtok() — TypeError for non-string operands (#4587, ext/standard/string.c)
--FILE--
<?php
try {
    strtok([], ',');
    echo "uncaught array arg1\n";
} catch (TypeError $e) {
    echo 'arg1: ', $e->getMessage(), "\n";
}
try {
    strtok('a,b,c', []);
    echo "uncaught array arg2\n";
} catch (TypeError $e) {
    echo 'arg2: ', $e->getMessage(), "\n";
}
$s = 'a,b,c';
echo strtok($s, ','), ' ', strtok(','), ' ', strtok(','), "\n";
--EXPECT--
arg1: strtok(): Argument #1 ($string) must be of type string, array given
arg2: strtok(): Argument #2 ($token) must be of type ?string, array given
a b c

--TEST--
Language: enum case as dynamic property name throws catchable Error (#6206, zend_operators.c)
--FILE--
<?php
$o = new stdClass();
enum E { case A; }
try {
    $o->{E::A} = 1;
    echo "no catch\n";
} catch (Throwable $e) {
    echo 'caught ', $e::class, ': ', $e->getMessage(), "\n";
}

$name = E::A;
try {
    $o->$name = 2;
    echo "var no catch\n";
} catch (Throwable $e) {
    echo 'var caught ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    $x = $o->{E::A};
    echo "fetch no catch\n";
} catch (Throwable $e) {
    echo 'fetch caught ', $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
caught Error: Object of class E could not be converted to string
var caught Error: Object of class E could not be converted to string
fetch caught Error: Object of class E could not be converted to string

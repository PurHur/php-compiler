--TEST--
Language: $false->prop +=/.= emits assign Error only (#30077)
--FILE--
<?php
error_reporting(E_ALL);

$x = false;
try {
    $x->a += 1;
} catch (Throwable $e) {
    echo 'PLUS:', get_class($e), ': ', $e->getMessage(), "\n";
}

$y = false;
try {
    $y->a .= 'z';
} catch (Throwable $e) {
    echo 'CONCAT:', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
PLUS:Error: Attempt to assign property "a" on false
CONCAT:Error: Attempt to assign property "a" on false

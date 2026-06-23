--TEST--
Language: array spread/unpack preserves enum case values (#9893, zend_hash.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

$a = [E::A];
$b = [...$a, E::B];

var_export($b[0] === E::A);
echo "\n";
var_export($b[0] instanceof E);
echo "\n";
echo get_debug_type($b[0]), "\n";

function f(...$args): array
{
    return $args;
}

$unpacked = f(...$a);
var_export($unpacked[0] instanceof E);
echo "\n";
--EXPECT--
true
true
E
true

--TEST--
Language: enum case in define() and ConstName::class (#5440)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

define('C', E::A);

echo C::class, "\n";
echo C->name, "\n";
--EXPECT--
C
A

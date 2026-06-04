--TEST--
Language: enum case in file-scope const (E::A) and ConstName::class (#5440)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'x';
}

const C = E::A;

echo C::class, "\n";
echo C->name, "\n";
--EXPECT--
C
A

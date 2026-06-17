--TEST--
Language: enum implements forward-referenced interface — inherited constants (#9302, zend_enum.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string implements I {
    case A = 'a';
}
interface I { public const X = 'iface'; }
var_export(E::X);
echo "\n";

enum U implements J {
    case B;
}
interface J { public const Y = 99; }
var_export(U::Y);
echo "\n";
--EXPECT--
'iface'
99

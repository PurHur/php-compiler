--TEST--
Language: class/interface const with enum case preserves enum object (#9712, zend_constants.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }

class C { public const X = E::A; }
interface I { public const Y = E::A; }

echo get_debug_type(C::X), "\n";
echo get_debug_type(I::Y), "\n";
var_export(C::X === E::A);
echo "\n";
var_export(I::Y === E::A);
echo "\n";
--EXPECT--
E
E
true
true

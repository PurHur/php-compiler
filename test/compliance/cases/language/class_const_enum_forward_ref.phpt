--TEST--
Language: class constant enum case forward reference (#9664, zend_constants.c)
--FILE--
<?php
class C {
    public const ITEM = E::A;
}
enum E: int { case A = 1; }
var_export(C::ITEM);
echo "\n";
echo C::ITEM->name, "\n";
var_export(constant('C::ITEM'));
echo "\n";
--EXPECT--
\E::A
A
\E::A

--TEST--
language: inline parenthesized builtin first-class callable invoke (#10472, zend_compile.c)
--FILE--
<?php
echo (strlen(...))('abc'), "\n";

enum E: string {
    case A = 'a';
}
echo (E::from(...))('a')->name, "\n";
var_export((E::tryFrom(...))('z'));
--EXPECT--
3
A
NULL

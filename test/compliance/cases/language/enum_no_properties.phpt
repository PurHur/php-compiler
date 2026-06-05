--TEST--
Language: enum cannot include instance properties — compile-time fatal (#6005, zend_compile.c)
--FILE--
<?php
enum E: string {
    case A = 'a';
    public string $x = 'y';
}
echo E::A->x, "\n";
--EXPECT_EXIT--
255

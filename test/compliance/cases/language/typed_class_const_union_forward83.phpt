--TEST--
Language: typed class constants with union types int|string (#6886, zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed class constants require PHP 8.3+');
}
?>
--FILE--
<?php
class C {
    public const int|string X = 1;
}
class D {
    public const int|string Y = 'a';
}
var_dump(C::X, D::Y);
--EXPECT--
int(1)
string(1) "a"

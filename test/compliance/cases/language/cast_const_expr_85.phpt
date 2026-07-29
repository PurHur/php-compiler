--TEST--
Language: casts in constant expressions under PROFILE=8.5 (#24947, Zend/zend_ast.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
const X = (int) "42";
echo X, "\n";
echo gettype(X), "\n";
class C {
    public const int Y = (int) "7";
    public const string S = (string) 1;
    public const bool B = (bool) 1;
    public const float F = (float) "1.5";
}
echo C::Y, "\n";
echo C::S, "\n";
var_export(C::B);
echo "\n";
echo C::F, "\n";
--EXPECT--
42
integer
7
1
true
1.5

--TEST--
Language: typed class constants under PHP_COMPILER_PROFILE=8.3 (#23757, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
class C {
    const string S = 'hello';
    const int I = 7;
    const bool B = true;
    const array A = [1, 2];
}
interface I {
    const string X = 'i';
}
enum E {
    public const string Y = 'y';
}
echo C::S, C::I, C::B ? 'T' : 'F', C::A[1], I::X, E::Y, "\n";
--EXPECT--
hello7T2iy

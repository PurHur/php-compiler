--TEST--
direct trait constant access T::CONST must throw Error (issue #4973, Zend zend_compile.c)
--FILE--
<?php
trait T {
    public const X = 7;
}
class C {
    use T;
}
var_dump(C::X);
try {
    var_dump(T::X);
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
int(7)
Cannot access trait constant T::X directly

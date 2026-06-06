--TEST--
Language: backed enum scalar casts — php-src legacy coerce (#6961, zend_operators.c)
--FILE--
<?php
enum E: int
{
    case Zero = 0;
    case FortyTwo = 42;
}

enum U
{
    case A;
}

echo 'int: ', @(int) E::FortyTwo, "\n";
echo 'float: ', @(float) E::FortyTwo, "\n";
echo 'bool_zero: ', (bool) E::Zero ? 'true' : 'false', "\n";
echo 'bool_forty_two: ', (bool) E::FortyTwo ? 'true' : 'false', "\n";

try {
    (string) E::FortyTwo;
    echo "string: fail\n";
} catch (Error $e) {
    echo 'string: ', $e->getMessage(), "\n";
}

try {
    strval(E::FortyTwo);
    echo "strval: fail\n";
} catch (Error $e) {
    echo 'strval: ', $e->getMessage(), "\n";
}

try {
    (string) U::A;
    echo "unit_string: fail\n";
} catch (Error $e) {
    echo 'unit_string: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
int: 1
float: 1
bool_zero: true
bool_forty_two: true
string: Object of class E could not be converted to string
strval: Object of class E could not be converted to string
unit_string: Object of class U could not be converted to string

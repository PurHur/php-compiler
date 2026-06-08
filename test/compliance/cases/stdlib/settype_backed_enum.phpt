--TEST--
stdlib settype() on backed enum cases — E_WARNING + Zend scalar coercion (#5643, ext/standard/type.c)
--FILE--
<?php
enum E: int
{
    case A = 42;
}

$x = E::A;
@settype($x, 'int');
echo 'int: ', $x, ' ', gettype($x), "\n";
@settype($x, 'int');
$err = error_get_last();
echo 'warning: ', $err['message'], "\n";

$x = E::A;
@settype($x, 'float');
echo 'float: ', $x, ' ', gettype($x), "\n";

$x = E::A;
@settype($x, 'bool');
echo 'bool: ', (int) $x, ' ', gettype($x), "\n";

$x = E::A;
@settype($x, 'array');
echo 'array: ', $x['name'], ' ', $x['value'], "\n";
--EXPECT--
int: 1 integer
warning: Object of class E could not be converted to int
float: 1 double
bool: 1 boolean
array: A 42

--TEST--
Language: (int)/(float) cast on backed enum case — E_WARNING + 1 (#5714, zend_operators.c)
--FILE--
<?php
enum E: int
{
    case A = 1;
    case B = 2;
}

enum S: string
{
    case X = '42';
}

echo 'E::A: ', @(int) E::A, "\n";
$e = E::B;
echo 'var: ', @(int) $e, "\n";
@ (int) $e;
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";
echo 'S::X: ', @(int) S::X, "\n";
echo 'float: ', @(float) E::A, "\n";
?>
--EXPECT--
E::A: 1
var: 1
warning: Object of class E could not be converted to int
S::X: 1
float: 1

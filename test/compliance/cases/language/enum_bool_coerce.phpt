--TEST--
Language: (bool), boolval(), assert(), settype('bool') on enum cases (#5689, zend_operators.c)
--FILE--
<?php
enum U
{
    case A;
}

enum I: int
{
    case B = 1;
}

echo (int) (bool) U::A, "\n";
echo (int) (bool) I::B, "\n";
echo (int) boolval(U::A), "\n";
echo (int) boolval(I::B), "\n";
echo (int) assert(U::A), "\n";
echo (int) assert(I::B), "\n";

$u = U::A;
settype($u, 'bool');
echo (int) $u, "\n";
$i = I::B;
settype($i, 'bool');
echo (int) $i, "\n";
?>
--EXPECT--
1
1
1
1
1
1
1
1

--TEST--
Language: ternary/elvis as call arg keeps sibling Array_/ConstFetch independent (#25337, Zend/zend_compile.c)
--FILE--
<?php
$x = 'C';
echo json_encode(array_merge([1], $x ? [2] : [3])), "\n";
const FLAG = 3;
function twoway(int $a, string $b): string
{
    return "$a:$b";
}
echo twoway(FLAG, 'C' ?: 'D'), "\n";
setlocale(LC_COLLATE, 'C' ?: 'POSIX');
echo "setlocale=ok\n";
?>
--EXPECT--
[1,2]
3:C
setlocale=ok

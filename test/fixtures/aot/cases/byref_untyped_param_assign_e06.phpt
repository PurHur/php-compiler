--TEST--
AOT: untyped by-ref formal assigned from another param matches Zend (e06_byref)
--FILE--
<?php
function g(&$r, $v)
{
    $r = $v;
}
$out = null;
g($out, 5);
echo $out, "\n";
$arr = [3, 1];
sort($arr);
print_r($arr);
--EXPECT--
5
Array
(
    [0] => 1
    [1] => 3
)
--EXPECT_EXIT--
0

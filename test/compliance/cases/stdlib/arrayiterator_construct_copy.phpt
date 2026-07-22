--TEST--
stdlib ArrayIterator::__construct copies array (issue #22020, ext/spl/spl_array.c)
--FILE--
<?php
$a = [1, 2, 3];
$it = new ArrayIterator($a);
foreach ($it as &$v) {
    $v *= 10;
}
unset($v);
echo 'src=';
var_export($a);
echo "\n";
echo 'it=';
var_export(iterator_to_array($it));
echo "\n";
$b = [1, 2, 3];
$rit = new RecursiveArrayIterator($b);
foreach ($rit as &$v2) {
    $v2 *= 10;
}
unset($v2);
echo 'rsrc=';
var_export($b);
echo "\n";
echo 'rit=';
var_export(iterator_to_array($rit));
echo "\n";
--EXPECT--
src=array (
  0 => 1,
  1 => 2,
  2 => 3,
)
it=array (
  0 => 10,
  1 => 20,
  2 => 30,
)
rsrc=array (
  0 => 1,
  1 => 2,
  2 => 3,
)
rit=array (
  0 => 10,
  1 => 20,
  2 => 30,
)

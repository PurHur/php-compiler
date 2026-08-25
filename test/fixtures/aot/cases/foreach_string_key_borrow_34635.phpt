--TEST--
AOT foreach string-keyed HT must separate keys (#34635)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2, 'c' => 3];
foreach ($a as $k => $v) {
}
var_dump($a);
$b = ['x' => 10, 'y' => 20];
foreach ($b as $k => $v) {
    echo "$k=$v\n";
}
var_export($b);
echo "\n";
$ao = new ArrayObject(['a' => 1, 'b' => 2]);
$ao['c'] = 3;
echo $ao->count(), "\n";
foreach ($ao as $k => $v) {
    echo "$k=$v\n";
}
echo $ao->getArrayCopy()['b'], "\n";
--EXPECT--
array(3) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
  ["c"]=>
  int(3)
}
x=10
y=20
array (
  'x' => 10,
  'y' => 20,
)
3
a=1
b=2
c=3
2

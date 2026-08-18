--TEST--
foreach-by-ref append during iteration leaves no IS_REFERENCE on iterated slots (Zend/zend_execute.c, #32128)
--FILE--
<?php
$arr = [1, 2];
foreach ($arr as &$v) {
    if ($v === 2) {
        $arr[] = 3;
    }
}
unset($v);
var_dump($arr);

echo "--- residual last-slot only ---\n";
$b = [1, 2];
foreach ($b as &$v) {
    if ($v === 2) {
        $b[] = 3;
    }
}
$v = 99;
var_dump($b);
unset($v);

echo "--- object property ---\n";
class Box32128 {
    public array $items = [1, 2];
}
$o = new Box32128();
foreach ($o->items as &$v) {
    if ($v === 2) {
        $o->items[] = 3;
    }
}
unset($v);
var_dump($o->items);
?>
--EXPECT--
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
--- residual last-slot only ---
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  &int(99)
}
--- object property ---
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}

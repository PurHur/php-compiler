--TEST--
foreach by-ref then unset($v) drops IS_REFERENCE markers (Zend/zend_variables.c, #31936)
--FILE--
<?php
$a = [1, 2, 3];
foreach ($a as &$v) {
    $v *= 2;
}
unset($v);
var_dump($a);

echo "--- before unset ---\n";
$b = [1, 2, 3];
foreach ($b as &$v) {
    $v *= 2;
}
var_dump($b);
unset($v);

echo "--- extra alias ---\n";
$c = [1, 2, 3];
foreach ($c as &$v) {
    $v *= 2;
}
$keep =& $v;
unset($v);
var_dump($c);
unset($keep);
var_dump($c);
--EXPECT--
array(3) {
  [0]=>
  int(2)
  [1]=>
  int(4)
  [2]=>
  int(6)
}
--- before unset ---
array(3) {
  [0]=>
  int(2)
  [1]=>
  int(4)
  [2]=>
  &int(6)
}
--- extra alias ---
array(3) {
  [0]=>
  int(2)
  [1]=>
  int(4)
  [2]=>
  &int(6)
}
array(3) {
  [0]=>
  int(2)
  [1]=>
  int(4)
  [2]=>
  int(6)
}

--TEST--
language WeakMap var_dump key/value pairs + opaque var_export (#24522, Zend/zend_weakrefs.c)
--FILE--
<?php
$empty = new WeakMap();
var_dump($empty);
echo 'empty_export=';
var_export($empty);
echo "\n";

$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = ['a' => 1];
var_dump($wm);
echo 'export=';
var_export($wm);
echo "\n";

$leak = str_contains(print_r($wm, true), '__weak_map') ? '1' : '0';
echo 'leaks_storage=', $leak, "\n";
--EXPECTF--
object(WeakMap)#%d (0) {
}
empty_export=\WeakMap::__set_state(array(
))
object(WeakMap)#%d (1) {
  [0]=>
  array(2) {
    ["key"]=>
    object(stdClass)#%d (0) {
    }
    ["value"]=>
    array(1) {
      ["a"]=>
      int(1)
    }
  }
}
export=\WeakMap::__set_state(array(
))
leaks_storage=0

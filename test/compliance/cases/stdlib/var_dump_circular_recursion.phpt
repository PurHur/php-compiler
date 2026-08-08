--TEST--
stdlib var_dump()/debug_zval_dump() circular array — *RECURSION* guard (issue #28795)
--FILE--
<?php
$a = [];
$a[0] = &$a;
var_dump($a);
echo "---\n";
debug_zval_dump($a);
echo "---\n";
$o = new stdClass();
$o->x = $o;
var_dump($o);
echo "---\n";
$b = [];
$b['self'] = &$b;
var_dump($b);
--EXPECTF--
array(1) {
  [0]=>
  *RECURSION*
}
---
array(1) refcount(%d){
  [0]=>
  reference refcount(%d) {
    *RECURSION*
  }
}
---
object(stdClass)#%d (1) {
  ["x"]=>
  *RECURSION*
}
---
array(1) {
  ["self"]=>
  *RECURSION*
}

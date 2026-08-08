--TEST--
stdlib var_dump()/debug_zval_dump() circular array/object — *RECURSION* guard (issues #28795, #28815)
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
echo "---\n";
// Mutual object cycle — Zend *RECURSION* (issue #28815; sibling array fix #28795).
class N
{
    public $next;
}
$n1 = new N();
$n2 = new N();
$n1->next = $n2;
$n2->next = $n1;
var_dump($n1);
echo "---\n";
debug_zval_dump($n1);
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
---
object(N)#%d (1) {
  ["next"]=>
  object(N)#%d (1) {
    ["next"]=>
    *RECURSION*
  }
}
---
object(N)#%d (1) refcount(%d){
  ["next"]=>
  object(N)#%d (1) refcount(%d){
    ["next"]=>
    *RECURSION*
  }
}

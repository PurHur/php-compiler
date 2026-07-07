--TEST--
stdlib debug_zval_dump() — reference refcount on aliased array element (#4709)
--FILE--
<?php
$a = [1, 2];
$b = &$a[0];
debug_zval_dump($a, $b);
--EXPECT--
array(2) refcount(2){
  [0]=>
  reference refcount(2) {
    int(1)
  }
  [1]=>
  int(2)
}
int(1)

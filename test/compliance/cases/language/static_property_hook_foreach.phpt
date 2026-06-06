--TEST--
Static property hooks invoke get hook in foreach/offset reads (issue #6566, zend_property_hooks.c)
--FILE--
<?php
class C {
    public static array $items {
        get => [1, 2, 3];
    }
}
foreach (C::$items as $v) {
    echo $v, "\n";
}
echo C::$items[0], "\n";
var_dump(C::$items);

class Base {
    public static array $shared {
        get => [4, 5, 6];
    }
}
class Child extends Base {}
foreach (Child::$shared as $v) {
    echo $v, "\n";
}
echo Child::$shared[0], "\n";
--EXPECT--
1
2
3
1
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
4
5
6
4

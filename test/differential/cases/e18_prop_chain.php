<?php class A{public $b;} class B{public $v="V";} $a=new A; $a->b=new B; function f($p,$q){echo "$p|$q\n";} f($a->b->v, $a->b->v);

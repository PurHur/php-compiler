--TEST--
AOT: dynamic prop write on object-typed stdClass receiver (#34395)
--FILE--
<?php
class A {
    public $o;
    public function __construct()
    {
        $this->o = new stdClass;
        $this->o->x = 1;
    }
}
echo (new A)->o->x, "\n";
class B extends A {}
echo (new B)->o->x, "\n";
--EXPECT--
1
1
--EXPECT_EXIT--
0

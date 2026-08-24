<?php
class A {
    public $o;
    public function __construct()
    {
        $this->o = new stdClass;
        $this->o->x = 1;
    }
}
class B extends A {}
echo (new B)->o->x, "\n";

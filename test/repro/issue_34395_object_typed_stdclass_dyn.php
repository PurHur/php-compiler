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

<?php
/**
 * #32367 — untyped $this->prop assign in an instance method, and typed assign in __construct.
 * php-src: Zend/zend_vm_def.h ZEND_ASSIGN_OBJ / zend_object_handlers.c zend_std_write_property.
 */
class A
{
    public $x;

    public function set($v)
    {
        $this->x = $v;
    }
}
$a = new A();
$a->set(7);
echo $a->x, "\n";

class T
{
    public int $x;

    public function __construct($x)
    {
        $this->x = $x;
    }
}
echo (new T(7))->x, "\n";

class P
{
    public function __construct(public $x)
    {
    }
}
echo (new P(1))->x, "\n";

class D
{
    public $x = 1;
}
echo (new D())->x, "\n";

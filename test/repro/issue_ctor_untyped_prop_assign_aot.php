<?php
/**
 * #32363 — untyped instance property write inside __construct.
 * php-src: Zend/zend_vm_def.h ZEND_ASSIGN_OBJ / zend_object_handlers.c zend_std_write_property.
 */
class A
{
    public $x;

    public function __construct($x)
    {
        $this->x = $x;
    }
}
echo (new A(7))->x, "\n";

class B extends A
{
    public function __construct()
    {
        parent::__construct(9);
    }
}
echo (new B())->x, "\n";

class C
{
    public $x = 1;
}
echo (new C())->x, "\n";

<?php
/**
 * #32349 — constructor property promotion of untyped/string/mixed properties.
 * php-src: Zend/zend_compile.c ZEND_ACC_PROMOTTED → ZEND_ASSIGN_OBJ in __construct.
 *
 * Must pass an overriding argument (`new A(7)`), not only the default (`new A()`).
 * #32360 made compile verify; AOT still ignored the argument until the VALUE-slot
 * write fetch skipped `__object__load_value_slot`.
 */
class A
{
    public function __construct(public $x = 1)
    {
    }
}
echo (new A())->x, '|', (new A(7))->x, "\n";

class B
{
    public function __construct(public $x)
    {
    }
}
echo (new B(3))->x, "\n";

class C
{
    public function __construct(public string $x = 'a')
    {
    }
}
echo (new C())->x, (new C('b'))->x, "\n";

class D
{
    public function __construct(public int $x = 1)
    {
    }
}
echo (new D())->x, (new D(4))->x, "\n";

class E
{
    public $x = 1;
}
echo (new E())->x, "\n";

class F
{
    public function __construct(public mixed $x = 2)
    {
    }
}
echo (new F())->x, '|', (new F(9))->x, "\n";

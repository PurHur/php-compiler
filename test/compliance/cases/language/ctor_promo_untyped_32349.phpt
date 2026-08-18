--TEST--
Language: constructor property promotion untyped/string/mixed (#32349)
--FILE--
<?php
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
--EXPECT--
1|7
3
ab
14
1
2|9

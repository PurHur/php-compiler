--TEST--
AOT: untyped/string/mixed constructor property promotion (#32349)
--FILE--
<?php
class A
{
    public function __construct(public $x = 1)
    {
    }
}
echo (new A())->x, "\n";

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
echo (new C())->x, "\n";

class D
{
    public function __construct(public int $x = 1)
    {
    }
}
echo (new D())->x, "\n";

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
echo (new F())->x, "\n";
--EXPECT--
1
3
a
1
1
2
--EXPECT_EXIT--
0

--TEST--
Language: untyped $this->prop assign in constructor (#32363)
--FILE--
<?php
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
--EXPECT--
7
9
1

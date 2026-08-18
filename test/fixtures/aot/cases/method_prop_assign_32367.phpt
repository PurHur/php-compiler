--TEST--
AOT: $this->prop assign in instance method and typed constructor (#32367)
--FILE--
<?php
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
--EXPECT--
7
7
1
1
--EXPECT_EXIT--
0

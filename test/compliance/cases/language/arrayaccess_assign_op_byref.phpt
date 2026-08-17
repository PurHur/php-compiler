--TEST--
Language: ArrayAccess by-ref offsetGet assign-op writes live int (ZEND_ASSIGN_DIM_OP; #31947)
--FILE--
<?php
class A implements ArrayAccess
{
    private $d = [0 => 1];

    public function offsetExists(mixed $k): bool
    {
        return isset($this->d[$k]);
    }

    public function &offsetGet(mixed $k): mixed
    {
        return $this->d[$k];
    }

    public function offsetSet(mixed $k, mixed $v): void
    {
        $this->d[$k] = $v;
    }

    public function offsetUnset(mixed $k): void
    {
        unset($this->d[$k]);
    }
}

$inc = new A();
$inc[0]++;
echo 'inc=', $inc[0], "\n";

$plus = new A();
$plus[0] += 2;
echo 'plus=', $plus[0], "\n";

$minus = new A();
$minus[0] -= 1;
echo 'minus=', $minus[0], "\n";

$mul = new A();
$mul[0] *= 3;
echo 'mul=', $mul[0], "\n";
--EXPECT--
inc=2
plus=3
minus=0
mul=3

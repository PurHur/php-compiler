--TEST--
AOT/JIT: get_object_vars($this) from subclass omits parent private (untyped) (#35479)
--FILE--
<?php
class Parent35479 {
    public $a = 1;
    protected $b = 2;
    private $c = 3;
    public function fromParent(): string
    {
        $v = get_object_vars($this);
        ksort($v);
        return json_encode($v);
    }
}
class Child35479 extends Parent35479 {
    public function fromChild(): string
    {
        $v = get_object_vars($this);
        ksort($v);
        return json_encode($v);
    }
}
$c = new Child35479();
$g = get_object_vars($c);
ksort($g);
echo json_encode($g), "\n";
echo $c->fromParent(), "\n";
echo $c->fromChild(), "\n";
--EXPECT--
{"a":1}
{"a":1,"b":2,"c":3}
{"a":1,"b":2}

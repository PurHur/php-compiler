--TEST--
Language: foreach over object respects property visibility (#23430, Zend zend_execute.c)
--FILE--
<?php
class A {
    public $pub = "P";
    protected $pro = "R";
    private $pri = "V";
    public function inside() {
        $ks = [];
        foreach ($this as $k => $v) { $ks[] = "$k=$v"; }
        return implode(",", $ks);
    }
}
class B extends A {
    public function child() {
        $ks = [];
        foreach ($this as $k => $v) { $ks[] = "$k=$v"; }
        return implode(",", $ks);
    }
}
$ks = [];
foreach (new A() as $k => $v) { $ks[] = "$k=$v"; }
echo "ext:", implode(",", $ks), "\n";
echo "in:", (new A())->inside(), "\n";
echo "ch:", (new B())->child(), "\n";
$anon = new class {
    public $x = 1;
    private $y = 2;
};
$ks = [];
foreach ($anon as $k => $v) { $ks[] = "$k=$v"; }
echo "anon:", implode(",", $ks), "\n";
?>
--EXPECT--
ext:pub=P
in:pub=P,pro=R,pri=V
ch:pub=P,pro=R
anon:x=1

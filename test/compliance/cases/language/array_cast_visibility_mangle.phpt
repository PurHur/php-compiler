--TEST--
Language: (array) object cast — visibility-mangled keys (#5338)
--FILE--
<?php
class C5338 {
    private int $x = 1;
    protected int $y = 2;
    public int $z = 3;
}
$a = (array) new C5338();
echo count($a);
echo $a["\0C5338\0x"];
echo $a["\0*\0y"];
echo $a['z'];

class Base5338 { private int $p = 1; }
class Child5338 extends Base5338 {
    public function leak(): void {
        foreach ((array)$this as $k => $v) {
            echo $k === "\0Base5338\0p" ? '1' : '0';
        }
    }
}
(new Child5338())->leak();

$o = new stdClass();
$o->a = 'bare';
$b = (array) $o;
echo count($b);
echo $b['a'];
echo "\n";
--EXPECT--
312311bare

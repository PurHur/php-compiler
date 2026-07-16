--TEST--
Language: multi-arg ctor trailing scalar/flag prelude must not steal New_ args (#19738, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public const FLAG_A = 1;
    public const FLAG_B = 2;
    public function __construct(public $a, public $b, public int $flags) {}
}

$o = new C(new stdClass(), new stdClass(), C::FLAG_A | C::FLAG_B);
echo 'or=', get_class($o->a), ',', get_class($o->b), ',', $o->flags, "\n";

$o = new C(new stdClass(), new stdClass(), 1 + 2);
echo 'plus=', get_class($o->a), ',', get_class($o->b), ',', $o->flags, "\n";

$o = new C(new stdClass(), new stdClass(), 2 * 3);
echo 'mul=', get_class($o->a), ',', get_class($o->b), ',', $o->flags, "\n";

$o = new C(new stdClass(), new stdClass(), 1 << 2);
echo 'shift=', get_class($o->a), ',', get_class($o->b), ',', $o->flags, "\n";

$o = new C(new stdClass(), new stdClass(), -1);
echo 'uminus=', get_class($o->a), ',', get_class($o->b), ',', $o->flags, "\n";

$o = new C(new stdClass(), new stdClass(), (int) 1.5);
echo 'cast=', get_class($o->a), ',', get_class($o->b), ',', $o->flags, "\n";
--EXPECT--
or=stdClass,stdClass,3
plus=stdClass,stdClass,3
mul=stdClass,stdClass,6
shift=stdClass,stdClass,4
uminus=stdClass,stdClass,-1
cast=stdClass,stdClass,1

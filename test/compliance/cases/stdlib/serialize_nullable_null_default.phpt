--TEST--
stdlib serialize() nullable null-default property included (#14619, ext/standard/var.c)
--FILE--
<?php
class Box {
    public ?string $s = null;
    public string $t = 'x';
}
echo serialize(new Box), "\n";
$o = unserialize(serialize(new Box));
echo property_exists($o, 's') && $o->s === null && $o->t === 'x' ? "roundtrip\n" : "fail\n";
--EXPECT--
O:3:"Box":2:{s:1:"s";N;s:1:"t";s:1:"x";}
roundtrip

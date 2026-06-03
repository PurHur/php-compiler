--TEST--
Language: isset() on uninitialized typed property returns false without throwing (issue #4931, zend_object_handlers.c)
--FILE--
<?php
class Uninit {
    public int $x;
}
$o = new Uninit();
var_export(isset($o->x));
echo "\n";

class WithDefault {
    public int $x = 7;
}
$d = new WithDefault();
var_export(isset($d->x));
echo "\n";

$o->x = 0;
var_export(isset($o->x));
echo "\n";

class Nullable {
    public ?string $s;
}
$n = new Nullable();
var_export(isset($n->s));
echo "\n";
$n->s = null;
var_export(isset($n->s));
echo "\n";

class ReadonlyUninit {
    public readonly int $r;
}
$ro = new ReadonlyUninit();
var_export(isset($ro->r));
echo "\n";

class Parent_ {
    public int $p;
}
class Child extends Parent_ {}
$c = new Child();
var_export(isset($c->p));
echo "\n";
--EXPECT--
false
true
true
false
false
false
false

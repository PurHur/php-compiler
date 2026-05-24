--TEST--
unset() on an object property
--FILE--
<?php
class C {
    public $p = 1;
}
$o = new C();
unset($o->p);
echo isset($o->p) ? "y" : "n", "\n";
--EXPECT--
n

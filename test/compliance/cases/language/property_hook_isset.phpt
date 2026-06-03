--TEST--
isset()/empty() on property-hook virtual properties invoke get hook (issue #4586, zend_object_handlers.c)
--FILE--
<?php
class Box {
    public string $label {
        get => strtoupper($this->label);
        set (string $v) { $this->label = $v; }
    }
    public function __construct() { $this->label = 'hi'; }
}
$o = new Box();
var_export(isset($o->label));
echo "\n";
var_export(empty($o->label));
echo "\n";

class R {
    public int $n { get => 42; }
}
$r = new R();
var_export(isset($r->n));
echo ' ';
var_export(empty($r->n));
echo "\n";

class NullHook {
    public ?string $x {
        get => null;
    }
}
$n = new NullHook();
var_export(isset($n->x));
echo ' ';
var_export(empty($n->x));
echo "\n";
--EXPECT--
true
false
true false
false true

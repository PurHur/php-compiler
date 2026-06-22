--TEST--
isset()/empty() on property hooks — get+set probes backing; get-only virtual invokes get (#10392, #9832)
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

class GetInvoked {
    public ?string $x {
        get { echo "get runs for isset\n"; return null; }
    }
}
$g = new GetInvoked();
var_dump(isset($g->x));
echo "ok\n";
--EXPECT--
true
false
true false
false true
get runs for isset
bool(false)
ok

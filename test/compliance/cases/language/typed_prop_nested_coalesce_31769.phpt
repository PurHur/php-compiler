--TEST--
Language: nested ?? concat/call-arg on uninitialized typed properties is BP_VAR_IS (#31769)
--FILE--
<?php
class C {
    public int $x;
    public int $set = 7;
}
$o = new C;
echo "stmt="; var_export($o->x ?? "d"); echo "\n";
echo "concat=" . var_export($o->x ?? "d", true) . "\n";
echo "setstmt="; var_export($o->set ?? "d"); echo "\n";
echo "after_inst\n";

function take($v) {
    return $v;
}
echo "arg=" . take($o->x ?? "d") . "\n";
echo "after_arg\n";

class S {
    public static int $y;
}
echo "static="; var_export(S::$y ?? "d"); echo "\n";
echo "static_concat=" . var_export(S::$y ?? "d", true) . "\n";
echo "after_static\n";

try {
    class B { public int $bare; }
    $b = new B;
    echo $b->bare;
    echo "bare_reached\n";
} catch (Error $e) {
    echo "bare=", $e->getMessage(), "\n";
}
echo "isset="; var_export(isset($o->x)); echo "\n";
echo "empty="; var_export(empty($o->x)); echo "\n";
?>
--EXPECT--
stmt='d'
concat='d'
setstmt=7
after_inst
arg=d
after_arg
static='d'
static_concat='d'
after_static
bare=Typed property B::$bare must not be accessed before initialization
isset=false
empty=true

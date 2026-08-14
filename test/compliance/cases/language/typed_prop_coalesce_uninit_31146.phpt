--TEST--
Language: ?? / ??= on uninitialized typed properties is BP_VAR_IS (#31146)
--FILE--
<?php
class C {
    public int $x;
    public ?int $z;
    public int $set = 7;
}
$o = new C;
echo "isset="; var_export(isset($o->x)); echo "\n";
echo "empty="; var_export(empty($o->x)); echo "\n";
echo "coalesce="; var_export($o->x ?? "d"); echo "\n";
echo "set="; var_export($o->set ?? "d"); echo "\n";
$o->z ??= 5;
echo "nullcoalassign=", $o->z, "\n";
$o->x ??= 9;
echo "intcoalassign=", $o->x, "\n";
class S {
    public static int $y;
    public static int $set = 3;
}
echo "static_isset="; var_export(isset(S::$y)); echo "\n";
echo "static="; var_export(S::$y ?? "d"); echo "\n";
echo "static_set="; var_export(S::$set ?? "d"); echo "\n";
S::$y ??= 4;
echo "static_assign=", S::$y, "\n";
try {
    class B { public int $bare; }
    $b = new B;
    echo $b->bare;
    echo "bare_reached\n";
} catch (Error $e) {
    echo "bare=", $e->getMessage(), "\n";
}
?>
--EXPECT--
isset=false
empty=true
coalesce='d'
set=7
nullcoalassign=5
intcoalassign=9
static_isset=false
static='d'
static_set=3
static_assign=4
bare=Typed property B::$bare must not be accessed before initialization

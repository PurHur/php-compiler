--TEST--
Language: isset/empty/?? on dim of uninitialized typed property is BP_VAR_IS (#31783)
--FILE--
<?php
class C {
    public array $a;
    public ?array $n;
    public string $s;
}
$o = new C;
echo 'isset_a0=', isset($o->a[0]) ? '1' : '0', "\n";
echo 'empty_a0=', empty($o->a[0]) ? '1' : '0', "\n";
echo 'isset_nk=', isset($o->n['k']) ? '1' : '0', "\n";
echo 'empty_nk=', empty($o->n['k']) ? '1' : '0', "\n";
echo 'coalesce_a0=';
var_export($o->a[0] ?? 'd');
echo "\n";
echo 'isset_s0=', isset($o->s[0]) ? '1' : '0', "\n";
echo 'empty_s0=', empty($o->s[0]) ? '1' : '0', "\n";
class S {
    public static array $a;
    public static ?array $n;
}
echo 'static_isset0=', isset(S::$a[0]) ? '1' : '0', "\n";
echo 'static_empty0=', empty(S::$a[0]) ? '1' : '0', "\n";
echo 'static_coalesce=';
var_export(S::$a[0] ?? 'd');
echo "\n";
echo 'static_issetn=', isset(S::$n['k']) ? '1' : '0', "\n";
try {
    class B { public array $bare; }
    $b = new B;
    echo $b->bare[0];
    echo "bare_dim_reached\n";
} catch (Error $e) {
    echo 'bare_dim=', $e->getMessage(), "\n";
}
try {
    class R { public array $r; }
    $r = new R;
    echo $r->r;
    echo "bare_reached\n";
} catch (Error $e) {
    echo 'bare=', $e->getMessage(), "\n";
}
echo "after\n";
?>
--EXPECT--
isset_a0=0
empty_a0=1
isset_nk=0
empty_nk=1
coalesce_a0='d'
isset_s0=0
empty_s0=1
static_isset0=0
static_empty0=1
static_coalesce='d'
static_issetn=0
bare_dim=Typed property B::$bare must not be accessed before initialization
bare=Typed property R::$r must not be accessed before initialization
after

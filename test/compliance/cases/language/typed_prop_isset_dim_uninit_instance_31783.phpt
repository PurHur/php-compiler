--TEST--
Language: isset/empty/?? on dim of uninitialized typed instance property is BP_VAR_IS (#31783, JIT)
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
echo 'coalesce_a0=';
var_export($o->a[0] ?? 'd');
echo "\n";
echo 'isset_s0=', isset($o->s[0]) ? '1' : '0', "\n";
try {
    class B { public array $bare; }
    $b = new B;
    echo $b->bare[0];
    echo "bare_dim_reached\n";
} catch (Error $e) {
    echo 'bare_dim=', $e->getMessage(), "\n";
}
echo "after\n";
?>
--EXPECT--
isset_a0=0
empty_a0=1
isset_nk=0
coalesce_a0='d'
isset_s0=0
bare_dim=Typed property B::$bare must not be accessed before initialization
after

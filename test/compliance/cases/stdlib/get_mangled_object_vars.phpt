--TEST--
Stdlib: get_mangled_object_vars() — mangled private/protected keys (VM, #3497)
--FILE--
<?php
class Base3497 {
    private string $secret = 'hidden';
    protected int $n = 1;
}
class Child3497 extends Base3497 {
    public string $visible = 'ok';
}
$o = new Child3497();
echo function_exists('get_mangled_object_vars') ? '1' : '0';
echo implode(',', array_keys(get_object_vars($o)));
$m = get_mangled_object_vars($o);
echo count($m);
echo $m['visible'];
echo $m["\0Base3497\0secret"];
echo $m["\0*\0n"];
echo "\n";
--EXPECT--
1visible,secret,n3okhidden1

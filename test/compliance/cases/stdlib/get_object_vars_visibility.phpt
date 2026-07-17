--TEST--
Stdlib: get_object_vars() hides parent private/protected from global scope (#4036, ext/standard/var.c)
--FILE--
<?php
class Parent4036 {
    public string $visible = 'yes';
    private string $secret = 'no';
    protected int $n = 1;
    public function keysFromParentScope(): string
    {
        return implode(',', array_keys(get_object_vars($this)));
    }
}
class Child4036 extends Parent4036 {
    public string $child = 'c';
    public function keysFromChildScope(): string
    {
        return implode(',', array_keys(get_object_vars($this)));
    }
}
class Stranger4036 {
    public function keys(object $o): string
    {
        return implode(',', array_keys(get_object_vars($o)));
    }
}
$c = new Child4036();
echo implode(',', array_keys(get_object_vars($c))), "\n";
echo $c->keysFromParentScope(), "\n";
echo $c->keysFromChildScope(), "\n";
echo (new Stranger4036())->keys($c), "\n";
$m = get_mangled_object_vars($c);
echo isset($m["\0Parent4036\0secret"]) ? '1' : '0';
echo isset($m["\0*\0n"]) ? '1' : '0';
echo "\n";
--EXPECT--
visible,child
visible,secret,n,child
visible,n,child
visible,child
11

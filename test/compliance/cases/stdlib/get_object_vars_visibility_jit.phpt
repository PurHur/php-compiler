--TEST--
Stdlib: get_object_vars() visibility from global vs child scope — JIT (#4036)
--FILE--
<?php
class Parent4036Jit {
    public string $visible = 'yes';
    private string $secret = 'no';
    protected int $n = 1;
    public function keysFromParentScope(): string
    {
        return implode(',', array_keys(get_object_vars($this)));
    }
}
class Child4036Jit extends Parent4036Jit {
    public string $child = 'c';
    public function keysFromChildScope(): string
    {
        return implode(',', array_keys(get_object_vars($this)));
    }
}
$c = new Child4036Jit();
echo implode(',', array_keys(get_object_vars($c))), "\n";
echo $c->keysFromParentScope(), "\n";
echo $c->keysFromChildScope(), "\n";
--EXPECT--
visible,child
visible,secret,n,child
visible,n,child

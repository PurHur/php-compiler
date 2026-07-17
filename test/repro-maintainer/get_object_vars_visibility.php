<?php
class Parent_ {
    public string $visible = 'yes';
    private string $secret = 'no';
    protected int $n = 1;
    public function keysFromParentScope(): string
    {
        return implode(',', array_keys(get_object_vars($this)));
    }
}
class Child extends Parent_ {
    public string $child = 'c';
    public function keysFromChildScope(): string
    {
        return implode(',', array_keys(get_object_vars($this)));
    }
}
$c = new Child();
echo implode(',', array_keys(get_object_vars($c))), "\n";
echo $c->keysFromParentScope(), "\n";
echo $c->keysFromChildScope(), "\n";

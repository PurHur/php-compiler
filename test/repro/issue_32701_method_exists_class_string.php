<?php
class C
{
    public function foo() {}
}
$name = 'C';
$c = new C;
var_dump(method_exists('C', 'foo'));
var_dump(method_exists($name, 'foo'));
var_dump(method_exists($c, 'foo'));
var_dump(method_exists($c, 'missing'));

<?php
class C
{
    public function foo() {}
}
$name = 'C';
var_dump(method_exists($name, 'foo'));
var_dump(method_exists('C', 'foo'));

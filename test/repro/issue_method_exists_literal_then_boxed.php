<?php
class C
{
    public function foo() {}
}
var_dump(method_exists('C', 'foo'));
$name = 'C';
var_dump(method_exists($name, 'foo'));

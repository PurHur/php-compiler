<?php

class C
{
    public int $x = 1;
    public string $foo = 'bar';
}

$c = new C();
var_dump(property_exists($c, 0));
var_dump(property_exists($c, 'foo'));
var_dump(property_exists('C', 0));
var_dump(property_exists('C', 'x'));

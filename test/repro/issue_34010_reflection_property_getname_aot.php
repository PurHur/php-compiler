<?php
class T
{
    public $x = 1;
}
$r = new ReflectionProperty(T::class, 'x');
echo $r->getName(), PHP_EOL;
echo $r->name, PHP_EOL;

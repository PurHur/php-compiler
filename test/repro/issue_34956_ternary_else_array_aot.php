<?php
class C
{
    public $x = 'hi';
}
$o = new C();
$f = false;
var_export($f ? [$o->x] : ['x']);
echo "\n";

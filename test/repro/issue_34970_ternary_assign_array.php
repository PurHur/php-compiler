<?php
class C
{
    public $a = 1;
}
$o = new C();
$x = true ? [$o->a] : [9];
var_export($x);
echo "\n";

<?php
class C
{
    public $x = 'hi';
}
$o = new C();
var_export($o ? [$o->x] : null);
echo "\n";

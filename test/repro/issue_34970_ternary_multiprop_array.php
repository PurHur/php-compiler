<?php
class C
{
    public $a = 1;
    public $b = 2;
}
$o = new C();
var_export($o ? [$o->a, $o->b] : null);
echo "\n";

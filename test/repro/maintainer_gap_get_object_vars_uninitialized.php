<?php

declare(strict_types=1);

class C
{
    public $x;
    public int $y;
    public ?int $z = null;
    protected $w;
    private $p;
}

$o = new C();
var_export(get_object_vars($o));
echo "\n";
var_export(get_mangled_object_vars($o));
echo "\n";

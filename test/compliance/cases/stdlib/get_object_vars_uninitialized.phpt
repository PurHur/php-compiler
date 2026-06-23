--TEST--
stdlib get_object_vars()/get_mangled_object_vars() uninitialized public property (#10846, ext/standard/var.c)
--FILE--
<?php
class C
{
    public $x;
    public int $y;
    public ?int $z = null;
    protected $w;
    private $p;
}

$o = new C();
$vars = get_object_vars($o);
echo array_key_exists('x', $vars) ? 'x' : '-', ' ';
echo array_key_exists('z', $vars) ? 'z' : '-', ' ';
echo array_key_exists('y', $vars) ? 'y' : '-', "\n";
echo null === $vars['x'] ? 'x-null' : 'x-set', ' ';
echo null === $vars['z'] ? 'z-null' : 'z-set', "\n";

$mangled = get_mangled_object_vars($o);
echo array_key_exists('x', $mangled) ? 'mx' : '-', ' ';
echo array_key_exists('z', $mangled) ? 'mz' : '-', ' ';
echo count($mangled), "\n";
?>
--EXPECT--
x z -
x-null z-null
mx mz 4

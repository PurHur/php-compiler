<?php
// Assignment to an undeclared property when __get exists (no __set) must still
// create a deprecated dynamic property (PHP 8.2 zend_std_write_property).
error_reporting(E_ALL);

class C
{
}

$o = new C();
$o->x = 1;
echo 'plain=', $o->x, "\n";

class M
{
    public function &__get($n)
    {
        $z = null;

        return $z;
    }
}

$m = new M();
$m->x = 1;
echo 'magic=', var_export($m->x, true), "\n";
echo 'isset=', isset($m->x) ? 'y' : 'n', "\n";
echo 'has_x=', array_key_exists('x', (array) $m) ? 'y' : 'n', "\n";

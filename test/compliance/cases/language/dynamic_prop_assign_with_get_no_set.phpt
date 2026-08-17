--TEST--
Language: assign undeclared prop with __get and no __set creates deprecated dynamic property (#31949, zend_object_handlers.c)
--FILE--
<?php
error_reporting(E_ALL);

class C31949
{
}

$o = new C31949();
$o->x = 1;
echo 'plain=', $o->x, "\n";

class M31949
{
    public function &__get($n)
    {
        $z = null;

        return $z;
    }
}

$m = new M31949();
$m->x = 1;
echo 'magic=', var_export($m->x, true), "\n";
echo 'isset=', isset($m->x) ? 'y' : 'n', "\n";
echo 'has_x=', array_key_exists('x', (array) $m) ? 'y' : 'n', "\n";
--EXPECTF--
PHP Deprecated:  Creation of dynamic property C31949::$x is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property M31949::$x is deprecated in %s on line %d
plain=1
magic=1
isset=y
has_x=y

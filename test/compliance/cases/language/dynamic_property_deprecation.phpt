--TEST--
Language: dynamic property deprecation on undeclared write (PHP 8.2+, issues #3253, #14839, #19848)
--FILE--
<?php
ini_set('error_reporting', '32767');

class EmptyC {}
$e = new EmptyC();
$e->x = 1;
echo $e->x, "\n";

class C {
    public int $x = 1;
}
$c = new C();
$c->y = 2;
echo $c->y, "\n";

#[\AllowDynamicProperties]
class D {}
$d = new D();
$d->z = 1;
echo $d->z, "\n";

$s = new stdClass();
$s->w = 1;
echo $s->w, "\n";
--EXPECTF--
PHP Deprecated:  Creation of dynamic property EmptyC::$x is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property C::$y is deprecated in %s on line %d
1
2
1
1

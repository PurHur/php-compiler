--TEST--
Language: dynamic property deprecation on undeclared write (PHP 8.2+, issue #3253)
--FILE--
<?php
ini_set('error_reporting', '32767');

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
--EXPECTF--
PHP Deprecated:  Creation of dynamic property C::$y is deprecated
2
1

--TEST--
stdlib unserialize() — allowed_classes allow-list / false / nested (#29065, var_unserializer.re)
--FILE--
<?php
class Allowed {
    public int $x = 1;
}
class Forbidden {
    public int $y = 2;
}
class Outer {
    public $inner;
}

$payload = serialize([new Allowed(), new Forbidden()]);

$listed = unserialize($payload, ['allowed_classes' => ['Allowed']]);
foreach ($listed as $v) {
    echo is_object($v) ? get_class($v) : var_export($v, true), "\n";
}

$none = unserialize($payload, ['allowed_classes' => false]);
foreach ($none as $v) {
    echo is_object($v) ? get_class($v) : var_export($v, true), "\n";
}

$all = unserialize($payload, ['allowed_classes' => true]);
foreach ($all as $v) {
    echo is_object($v) ? get_class($v) : var_export($v, true), "\n";
}

$outer = new Outer();
$outer->inner = new Forbidden();
$nested = unserialize(serialize($outer), ['allowed_classes' => ['Outer']]);
echo get_class($nested), "\n";
echo is_object($nested->inner) ? get_class($nested->inner) : '?', "\n";

$nestedFalse = unserialize(serialize($outer), ['allowed_classes' => false]);
echo get_class($nestedFalse), "\n";
var_export($nestedFalse);
echo "\n";
--EXPECT--
Allowed
__PHP_Incomplete_Class
__PHP_Incomplete_Class
__PHP_Incomplete_Class
Allowed
Forbidden
Outer
__PHP_Incomplete_Class
__PHP_Incomplete_Class
\__PHP_Incomplete_Class::__set_state(array(
   '__PHP_Incomplete_Class_Name' => 'Outer',
   'inner' => \__PHP_Incomplete_Class::__set_state(array(
     '__PHP_Incomplete_Class_Name' => 'Forbidden',
     'y' => 2,
  )),
))

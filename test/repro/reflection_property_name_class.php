<?php
// Issue #22504 — ReflectionProperty::$name + $class (php-src-strict)
class C
{
    public int $x = 1;
}

$r = new ReflectionProperty('C', 'x');
echo $r->getName(), "\n";
var_export($r->name);
echo "\n";
var_export($r->class);
echo "\n";
echo 'eq_name=', ($r->name === $r->getName()) ? '1' : '0', "\n";
echo 'eq_class=', ($r->class === $r->getDeclaringClass()->getName()) ? '1' : '0', "\n";
echo 'pe_name=', property_exists($r, 'name') ? '1' : '0', "\n";
echo 'pe_class=', property_exists($r, 'class') ? '1' : '0', "\n";
echo 'pe_property=', property_exists($r, 'property') ? '1' : '0', "\n";
echo 'pe_declaringClass=', property_exists($r, 'declaringClass') ? '1' : '0', "\n";
$via = (new ReflectionClass('C'))->getProperty('x');
echo 'via_name=';
var_export($via->name);
echo "\n";
echo 'via_class=';
var_export($via->class);
echo "\n";
print_r($r);
var_dump($r);

--TEST--
ReflectionProperty::$name is property name; $class is declaring class (#22504)
--FILE--
<?php
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
--EXPECTF--
x
'x'
'C'
eq_name=1
eq_class=1
pe_name=1
pe_class=1
pe_property=0
pe_declaringClass=0
via_name='x'
via_class='C'
ReflectionProperty Object
(
    [name] => x
    [class] => C
)
object(ReflectionProperty)#%d (2) {
  ["name"]=>
  string(1) "x"
  ["class"]=>
  string(1) "C"
}

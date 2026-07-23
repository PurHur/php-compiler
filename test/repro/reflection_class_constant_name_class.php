<?php
// Issue #22503 — ReflectionClassConstant::$name + $class (php-src-strict)
class C
{
    public const X = 1;
}

$r = new ReflectionClassConstant('C', 'X');
echo $r->getName(), "\n";
var_export($r->name);
echo "\n";
var_export($r->class);
echo "\n";
echo 'eq_name=', ($r->name === $r->getName()) ? '1' : '0', "\n";
echo 'eq_class=', ($r->class === $r->getDeclaringClass()->getName()) ? '1' : '0', "\n";
echo 'pe_name=', property_exists($r, 'name') ? '1' : '0', "\n";
echo 'pe_class=', property_exists($r, 'class') ? '1' : '0', "\n";
echo 'pe_constant=', property_exists($r, 'constant') ? '1' : '0', "\n";
$via = (new ReflectionClass('C'))->getReflectionConstant('X');
echo 'via_name=';
var_export($via->name);
echo "\n";
echo 'via_class=';
var_export($via->class);
echo "\n";
print_r($r);
var_dump($r);

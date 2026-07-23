<?php
// Issue #22505 — ReflectionEnumUnitCase/BackedCase::$name + $class (php-src-strict)
enum U
{
    case A;
}
enum E: int
{
    case A = 1;
}

foreach (['unit' => 'U', 'backed' => 'E'] as $label => $enumName) {
    $c = (new ReflectionEnum($enumName))->getCase('A');
    echo $label, '_name=';
    var_export($c->name);
    echo "\n";
    echo $label, '_class=';
    var_export($c->class);
    echo "\n";
    echo $label, '_eq_class=', ($c->class === $c->getEnum()->getName()) ? '1' : '0', "\n";
    echo $label, '_pe_class=', property_exists($c, 'class') ? '1' : '0', "\n";
    echo $label, '_pe_enumClass=', property_exists($c, 'enumClass') ? '1' : '0', "\n";
    echo $label, '_pe_name=', property_exists($c, 'name') ? '1' : '0', "\n";
}
$u = (new ReflectionEnum('U'))->getCase('A');
$b = (new ReflectionEnum('E'))->getCase('A');
var_dump($u);
var_dump($b);

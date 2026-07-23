--TEST--
Stdlib: ReflectionEnumUnitCase/BackedCase::$name + $class (php_reflection.c, #22505)
--FILE--
<?php
error_reporting(E_ALL);
enum U { case A; }
enum E: int { case A = 1; }
foreach (['unit' => 'U', 'backed' => 'E'] as $label => $enumName) {
    $c = (new ReflectionEnum($enumName))->getCase('A');
    echo $label, '_name=', $c->name, "\n";
    echo $label, '_class=', $c->class, "\n";
    echo $label, '_eq=', ($c->class === $c->getEnum()->getName()) ? '1' : '0', "\n";
    echo $label, '_pe_class=', property_exists($c, 'class') ? '1' : '0', "\n";
    echo $label, '_pe_enumClass=', property_exists($c, 'enumClass') ? '1' : '0', "\n";
}
--EXPECT--
unit_name=A
unit_class=U
unit_eq=1
unit_pe_class=1
unit_pe_enumClass=0
backed_name=A
backed_class=E
backed_eq=1
backed_pe_class=1
backed_pe_enumClass=0

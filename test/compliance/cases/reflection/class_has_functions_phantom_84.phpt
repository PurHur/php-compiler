--TEST--
Reflection: class_has_method/property/constant() phantoms on PROFILE≥8.4 — Zend has ReflectionClass::has* only (#28413)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['class_has_method', 'class_has_property', 'class_has_constant'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
class Probe {
    public int $p = 1;
    public const C = 1;
    public function m(): void {}
}
$rc = new ReflectionClass(Probe::class);
echo $rc->hasMethod('m') ? "hasMethod=1\n" : "hasMethod=0\n";
echo $rc->hasProperty('p') ? "hasProperty=1\n" : "hasProperty=0\n";
echo $rc->hasConstant('C') ? "hasConstant=1\n" : "hasConstant=0\n";
?>
--EXPECT--
class_has_method=0
class_has_property=0
class_has_constant=0
hasMethod=1
hasProperty=1
hasConstant=1

--TEST--
class_implements/parents/uses Reflection return array|false; autoload default true (#25498)
--FILE--
<?php
foreach (['class_implements', 'class_parents', 'class_uses'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
    $p0 = $r->getParameters()[0];
    $p1 = $r->getParameters()[1];
    echo '  p0=', $p0->getName(), ' type=', $p0->hasType() ? (string) $p0->getType() : '(none)', "\n";
    echo '  p1=', $p1->getName(), ' def=', var_export($p1->getDefaultValue(), true), "\n";
}

interface I25498 {}
class C25498 implements I25498 {}
$impl = class_implements(C25498::class);
echo 'runtime=', isset($impl['I25498']) ? 'Y' : 'N', "\n";
echo 'named=', var_export(class_implements(object_or_class: C25498::class, autoload: true) !== false, true), "\n";
?>
--EXPECT--
class_implements ret=array|false
  p0=object_or_class type=(none)
  p1=autoload def=true
class_parents ret=array|false
  p0=object_or_class type=(none)
  p1=autoload def=true
class_uses ret=array|false
  p0=object_or_class type=(none)
  p1=autoload def=true
runtime=Y
named=true

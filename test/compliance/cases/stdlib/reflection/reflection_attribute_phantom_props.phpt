--TEST--
ReflectionAttribute has no PHP-visible properties (#22513, ext/reflection/php_reflection.c)
--FILE--
<?php
#[Attribute] class Attr {}
#[Attr] class X {}
$ra = (new ReflectionClass(X::class))->getAttributes()[0];
foreach (['name', 'args', 'isRepeated', 'target'] as $p) {
    echo "property_exists $p=", var_export(property_exists($ra, $p), true), "\n";
    echo "isset $p=", var_export(isset($ra->$p), true), "\n";
}
echo 'getName=', $ra->getName(), "\n";
echo 'gov=', json_encode(get_object_vars($ra)), "\n";
$arr = (array) $ra;
echo 'array_keys=', json_encode(array_keys($arr)), "\n";
--EXPECT--
property_exists name=false
isset name=false
property_exists args=false
isset args=false
property_exists isRepeated=false
isset isRepeated=false
property_exists target=false
isset target=false
getName=Attr
gov=[]
array_keys=[]

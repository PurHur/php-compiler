--TEST--
ReflectionType abstract base — parent links and instanceof (#6594)
--FILE--
<?php
class C {
    public const string NAME = 'hi';
}
$t = (new ReflectionClassConstant(C::class, 'NAME'))->getType();
var_export(class_exists('ReflectionType'));
echo "\n";
var_export(get_parent_class($t));
echo "\n";
var_export($t instanceof ReflectionType);
echo "\n";
var_export(is_subclass_of('ReflectionNamedType', 'ReflectionType'));
echo "\n";
var_export(is_subclass_of('ReflectionUnionType', 'ReflectionType'));
echo "\n";
var_export(get_class($t));
?>
--EXPECT--
true
'ReflectionType'
true
true
true
'ReflectionNamedType'

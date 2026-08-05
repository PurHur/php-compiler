--TEST--
stdlib get_class_methods() Reflection object|string + null TypeError wording (#27706, Zend/zend_builtin_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('get_class_methods');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(), ' type=', (string) $p->getType(), ' return=', (string) $r->getReturnType(), "\n";
echo in_array('format', get_class_methods(object_or_class: DateTime::class), true) ? 'named_ok' : 'named_fail', "\n";
try {
    get_class_methods(null);
    echo "null accepted\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
name=object_or_class type=object|string return=array
named_ok
get_class_methods(): Argument #1 ($object_or_class) must be an object or a valid class name, null given

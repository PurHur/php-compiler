--TEST--
get_object_vars/get_class_methods named object/object_or_class arguments (VM, issue #23401)
--FILE--
<?php
class C
{
    public $a = 1;
}
echo get_object_vars(object: new C)['a'], PHP_EOL;
echo in_array('format', get_class_methods(object_or_class: DateTime::class), true) ? 'yes' : 'no', PHP_EOL;
foreach (['get_object_vars', 'get_class_methods'] as $f) {
    $rf = new ReflectionFunction($f);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), PHP_EOL;
}
try {
    get_object_vars(obj: new C);
    echo "obj accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    get_class_methods(class: DateTime::class);
    echo "class accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
1
yes
get_object_vars:object
get_class_methods:object_or_class
Unknown named parameter $obj
Unknown named parameter $class

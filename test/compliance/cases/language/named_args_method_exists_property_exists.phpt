--TEST--
method_exists/property_exists named object_or_class/method/property arguments (VM, issue #23399)
--FILE--
<?php
var_export(method_exists(object_or_class: DateTime::class, method: 'format'));
echo PHP_EOL;
var_export(property_exists(object_or_class: DateTime::class, property: 'date'));
echo PHP_EOL;
foreach (['method_exists', 'property_exists'] as $f) {
    $rf = new ReflectionFunction($f);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), PHP_EOL;
}
try {
    method_exists(object: DateTime::class, method: 'format');
    echo "object accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    property_exists(object_or_class: DateTime::class, property_name: 'date');
    echo "property_name accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
true
false
method_exists:object_or_class,method
property_exists:object_or_class,property
Unknown named parameter $object
Unknown named parameter $property_name

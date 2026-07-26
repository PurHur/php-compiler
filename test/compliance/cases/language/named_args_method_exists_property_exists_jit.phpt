--TEST--
method_exists/property_exists named object_or_class/method/property arguments (JIT, issue #23399)
--FILE--
<?php
var_export(method_exists(object_or_class: 'DateTime', method: 'format'));
echo "\n";
var_export(property_exists(object_or_class: 'DateTime', property: 'date'));
echo "\n";
--EXPECT--
true
false

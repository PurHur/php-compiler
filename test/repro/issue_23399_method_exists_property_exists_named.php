<?php
// Issue #23399 — method_exists/property_exists Zend stub named params
var_export(method_exists(object_or_class: DateTime::class, method: 'format'));
echo "\n";
var_export(property_exists(object_or_class: DateTime::class, property: 'date'));
echo "\n";
$rf = new ReflectionFunction('method_exists');
echo 'me=', $rf->getParameters()[0]->getName(), ',', $rf->getParameters()[1]->getName(), "\n";
$rf = new ReflectionFunction('property_exists');
echo 'pe=', $rf->getParameters()[0]->getName(), ',', $rf->getParameters()[1]->getName(), "\n";

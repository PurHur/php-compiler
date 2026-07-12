<?php
// #18298 — ReflectionMethod::$class must mirror declaring class name (ext/reflection/php_reflection.c)
$m = new ReflectionMethod('ArrayObject', 'offsetExists');
echo 'class_property=', ($m->class ?? 'NULL'), "\n";
echo 'name_property=', ($m->name ?? 'NULL'), "\n";
echo 'declaring_class=', $m->getDeclaringClass()->getName(), "\n";

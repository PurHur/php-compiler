<?php
// Issue #23401 — get_object_vars/get_class_methods Zend stub named params
class C
{
    public $a = 1;
}
echo get_object_vars(object: new C)['a'], "\n";
echo in_array('format', get_class_methods(object_or_class: DateTime::class), true) ? 'yes' : 'no', "\n";
$rf = new ReflectionFunction('get_object_vars');
echo 'gov=', $rf->getParameters()[0]->getName(), "\n";
$rf = new ReflectionFunction('get_class_methods');
echo 'gcm=', $rf->getParameters()[0]->getName(), "\n";

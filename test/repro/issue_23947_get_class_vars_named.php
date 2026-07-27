<?php
// Issue #23947 — get_class_vars Zend stub named param $class
class C23947
{
    public $a = 1;
}
echo array_key_exists('a', get_class_vars(class: 'C23947')) ? 'yes' : 'no', "\n";
$rf = new ReflectionFunction('get_class_vars');
echo 'gcv=', $rf->getParameters()[0]->getName(), "\n";
try {
    get_class_vars(class_name: 'C23947');
    echo "class_name accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

--TEST--
stdlib method_exists()/property_exists() string class autoloads (#26407, Zend/zend_builtin_functions.c)
--FILE--
<?php
spl_autoload_register(function ($c) {
    if ($c === 'Nope26407') {
        echo "A:$c\n";
    }
});
var_export(method_exists('Nope26407', 'm'));
echo "\n";
var_export(property_exists('Nope26407', 'x'));
echo "\n";
--EXPECT--
A:Nope26407
false
A:Nope26407
false

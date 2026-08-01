--TEST--
stdlib is_a()/is_subclass_of() string subject autoloads (#26406, Zend/zend_builtin_functions.c)
--FILE--
<?php
spl_autoload_register(function ($c) {
    if ($c === 'Child26406') {
        eval('class Parent26406 {} class Child26406 extends Parent26406 {}');
        echo "loaded\n";
    }
});
var_export(is_a('Child26406', 'Parent26406', true));
echo "\n";
var_export(is_subclass_of('Child26406', 'Parent26406'));
echo "\n";
// allow_string=false / two-arg is_a must not autoload
$hits = 0;
spl_autoload_register(function ($c) use (&$hits) {
    if ($c === 'Nope26406') {
        $hits++;
    }
});
var_export(is_a('Nope26406', 'X'));
echo "\n";
var_export(is_a('Nope26406', 'X', false));
echo "\n";
echo $hits === 0 ? "no-autoload-ok\n" : "autoload-leak\n";
--EXPECT--
loaded
true
true
false
false
no-autoload-ok

--TEST--
stdlib method_exists()/property_exists() string class autoloads JIT (#26407)
--FILE--
<?php
$dir = __DIR__ . '/method_property_exists_autoload_fixtures';
spl_autoload_register(function ($c) use ($dir) {
    $f = $dir . '/' . $c . '.php';
    if (is_file($f)) {
        echo "A:$c\n";
        require $f;
    }
});
var_export(method_exists('Nope26407J', 'm'));
echo "\n";
var_export(property_exists('Nope26407J', 'x'));
echo "\n";
--EXPECT--
A:Nope26407J
false
false
--EXTENSIONS--
llvm

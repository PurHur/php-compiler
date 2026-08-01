--TEST--
stdlib is_a()/is_subclass_of() string subject autoloads JIT (#26406)
--FILE--
<?php
$dir = __DIR__ . '/is_a_subclass_autoload_fixtures';
spl_autoload_register(function ($c) use ($dir) {
    $f = $dir . '/' . $c . '.php';
    if (!is_file($f)) {
        return;
    }
    if ($c === 'Child26406J') {
        echo "loaded\n";
    }
    require $f;
});
var_export(is_a('Child26406J', 'Parent26406J', true));
echo "\n";
var_export(is_subclass_of('Child26406J', 'Parent26406J'));
echo "\n";
--EXPECT--
loaded
true
true
--EXTENSIONS--
llvm

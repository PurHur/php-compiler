--TEST--
stdlib basename()/dirname()/pathinfo() null — TypeError on 8.4 forward profile JIT (#19256, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'basename' => static fn () => basename(null),
    'dirname' => static fn () => dirname(null),
    'pathinfo' => static fn () => pathinfo(null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(basename(''), true), "\n";
?>
--EXPECT--
basename(): Argument #1 ($path) must be of type string, null given
dirname(): Argument #1 ($path) must be of type string, null given
pathinfo(): Argument #1 ($path) must be of type string, null given
''

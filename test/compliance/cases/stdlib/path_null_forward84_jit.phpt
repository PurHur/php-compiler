--TEST--
stdlib basename()/dirname()/pathinfo() null — TypeError on 8.4 forward profile JIT (#20099, ext/standard/basename.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['basename', 'dirname', 'pathinfo'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
basename(): Argument #1 ($path) must be of type string, null given
dirname(): Argument #1 ($path) must be of type string, null given
pathinfo(): Argument #1 ($path) must be of type string, null given

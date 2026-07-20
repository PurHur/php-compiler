--TEST--
stdlib trim()/ltrim()/rtrim()/chop() null — TypeError on 8.4 forward profile JIT (#21350, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(trim(''), true), "\n";
?>
--EXPECT--
trim(): Argument #1 ($string) must be of type string, null given
ltrim(): Argument #1 ($string) must be of type string, null given
rtrim(): Argument #1 ($string) must be of type string, null given
chop(): Argument #1 ($string) must be of type string, null given
''

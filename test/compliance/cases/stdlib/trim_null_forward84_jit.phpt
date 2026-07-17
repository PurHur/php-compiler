--TEST--
stdlib trim()/ltrim()/rtrim()/chop() null — coerce on 8.4 forward profile JIT (#19983, ext/standard/string.c)
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
trim: uncaught
ltrim: uncaught
rtrim: uncaught
chop: uncaught
''

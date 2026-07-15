--TEST--
ext/phar Phar::running() — class exists and non-phar script returns empty (#3436, ext/phar/phar_object.c)
--FILE--
<?php
var_export(class_exists('Phar', false));
echo "\n";
var_export(extension_loaded('phar'));
echo "\n";
var_export(Phar::running());
echo "\n";
--EXPECT--
true
true
''

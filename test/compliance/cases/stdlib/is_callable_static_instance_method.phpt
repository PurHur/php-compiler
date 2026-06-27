--TEST--
stdlib is_callable() — class-string + instance method is false (#12545, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(is_callable([Exception::class, 'getMessage']));
echo "\n";
var_export(is_callable([new Exception('x'), 'getMessage']));
echo "\n";
var_export(is_callable(['stdClass', '__construct']));
echo "\n";
var_export(is_callable('Exception::getMessage'));
echo "\n";

class MagicCallStatic12545
{
    public static function __callStatic(string $name, array $args): void
    {
    }
}

var_export(is_callable([MagicCallStatic12545::class, 'missing']));
echo "\n";
--EXPECT--
false
true
false
false
true

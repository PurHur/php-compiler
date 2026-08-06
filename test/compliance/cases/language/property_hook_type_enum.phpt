--TEST--
Language: PropertyHookType string-backed builtin enum (#7222, #28345, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(enum_exists('PropertyHookType', false));
echo "\n";
var_export(PropertyHookType::Get->name);
echo "\n";
var_export(PropertyHookType::Get->value);
echo "\n";
var_export(PropertyHookType::Set->name);
echo "\n";
var_export(PropertyHookType::Set->value);
echo "\n";
echo (string) (new ReflectionEnum(PropertyHookType::class))->getBackingType();
echo "\n";
var_export(PropertyHookType::Get === PropertyHookType::Get);
echo "\n";
var_export(PropertyHookType::Get === PropertyHookType::Set);
echo "\n";
try {
    PropertyHookType::from('bogus');
    echo 'no throw';
} catch (ValueError $e) {
    echo $e->getMessage();
}
--EXPECT--
true
'Get'
'get'
'Set'
'set'
string
true
false
"bogus" is not a valid backing value for enum PropertyHookType

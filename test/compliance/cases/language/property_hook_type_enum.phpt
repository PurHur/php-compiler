--TEST--
Language: PropertyHookType builtin enum (#7222, Zend/zend_enum.def)
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
var_export(PropertyHookType::Get === PropertyHookType::Get);
echo "\n";
var_export(PropertyHookType::Get === PropertyHookType::Set);
echo "\n";
try {
    PropertyHookType::from(99);
    echo 'no throw';
} catch (ValueError $e) {
    echo $e->getMessage();
}
--EXPECT--
true
'Get'
0
'Set'
1
true
false
99 is not a valid backing value for enum PropertyHookType

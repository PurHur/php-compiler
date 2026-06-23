--TEST--
Stdlib: class_alias() on enum types (#5765, zend_API.c)
--FILE--
<?php
enum ClassAliasEnum5765: string { case A = 'x'; }
var_export(class_alias('ClassAliasEnum5765', 'ClassAliasEnumAlias5765'));
echo "\n";
var_export(enum_exists('ClassAliasEnumAlias5765'));
echo "\n";
var_export(class_exists('ClassAliasEnumAlias5765'));
echo "\n";
var_export(ClassAliasEnumAlias5765::A === ClassAliasEnum5765::A);
echo "\n";
--EXPECT--
true
true
true
true

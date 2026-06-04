--TEST--
Stdlib: class_alias() on interfaces and traits (#5329)
--FILE--
<?php
interface ClassAliasIface5329 {}
var_export(class_alias('ClassAliasIface5329', 'ClassAliasIfaceAlias5329'));
echo "\n";
var_export(interface_exists('ClassAliasIfaceAlias5329'));
echo "\n";

trait ClassAliasTrait5329 {}
var_export(class_alias('ClassAliasTrait5329', 'ClassAliasTraitAlias5329'));
echo "\n";
var_export(trait_exists('ClassAliasTraitAlias5329'));
echo "\n";

class ClassAliasImpl5329 implements ClassAliasIface5329 {}
$obj = new ClassAliasImpl5329();
var_export($obj instanceof ClassAliasIfaceAlias5329);
echo "\n";
--EXPECT--
true
true
true
true
true

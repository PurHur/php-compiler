<?php

declare(strict_types=1);

class ClassAliasChain11639
{
}

var_export(class_alias('ClassAliasChain11639', 'ClassAliasChainB11639'));
echo "\n";
var_export(class_alias('ClassAliasChainB11639', 'ClassAliasChainC11639'));
echo "\n";
var_export(class_exists('ClassAliasChainC11639', false));
echo "\n";
$obj = new ClassAliasChainC11639();
echo get_class($obj), "\n";

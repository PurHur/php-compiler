--TEST--
eval() must compile-error on unimplemented abstract property hooks (#7030, zend_property_hooks.c)
--FILE--
<?php
$ok = eval('abstract class BaseE { abstract public string $x { get; } } class ChildE extends BaseE {}');
echo $ok === false ? "eval-false\n" : "eval-not-false\n";
echo class_exists('ChildE', false) ? "child-exists\n" : "child-missing\n";
--EXPECT_EXIT--
255

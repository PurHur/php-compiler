--TEST--
AOT: get_declared_traits() after trait declaration (issue #3128)
--FILE--
<?php
trait DeclaredTraitT {}
$traits = get_declared_traits();
echo in_array('DeclaredTraitT', $traits, true) ? '1' : '0';
echo "\n";
--EXPECT--
1

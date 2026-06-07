--TEST--
AOT: get_declared_traits()/trait_exists() hide internal LazyGhostTrait (#7009)
--FILE--
<?php
declare(strict_types=1);

$traits = get_declared_traits();
echo count($traits), "\n";
echo in_array('LazyGhostTrait', $traits, true) ? '1' : '0';
echo "\n";
var_export(trait_exists('LazyGhostTrait'));
echo "\n";
trait UserTraitT {}
echo in_array('UserTraitT', get_declared_traits(), true) ? '1' : '0';
echo "\n";
--EXPECT--
0
0
false
1

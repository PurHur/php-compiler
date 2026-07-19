--TEST--
IntlDatePatternGenerator create/getBestPattern (#20740)
--FILE--
<?php
var_export(class_exists('IntlDatePatternGenerator'));
echo "\n";
$g = IntlDatePatternGenerator::create('en_US');
echo $g->getBestPattern('yMMMd'), "\n";
echo $g->getBestPattern('yMd'), "\n";
echo $g->getBestPattern('Hm'), "\n";
$g2 = new IntlDatePatternGenerator('de_DE');
echo $g2->getBestPattern('yMMMd'), "\n";
?>
--EXPECT--
true
MMM d, y
M/d/y
HH:mm
d. MMM y

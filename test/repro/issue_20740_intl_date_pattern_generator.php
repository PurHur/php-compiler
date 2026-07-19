<?php
// Repro for #20740 — IntlDatePatternGenerator::create/getBestPattern
var_export(class_exists('IntlDatePatternGenerator'));
echo "\n";
$g = IntlDatePatternGenerator::create('en_US');
echo $g->getBestPattern('yMMMd'), "\n";
echo $g->getBestPattern('yMd'), "\n";
$g2 = new IntlDatePatternGenerator('de_DE');
echo $g2->getBestPattern('yMMMd'), "\n";

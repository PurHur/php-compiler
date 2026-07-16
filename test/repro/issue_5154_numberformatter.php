<?php
// Repro for #5154 — NumberFormatter DECIMAL create/format
var_export(class_exists('NumberFormatter'));
echo "\n";
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo $fmt->format(1234.5), "\n";
$fmt2 = NumberFormatter::create('de_DE', NumberFormatter::DECIMAL);
echo $fmt2->format(1234.5), "\n";

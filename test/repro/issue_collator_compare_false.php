<?php
// Repro for #20753 — new Collator must register state so compare/getSortKey work
$c = new Collator('en_US');
var_export($c->compare('a', 'b'));
echo "\n";
var_export($c->compare('b', 'a'));
echo "\n";
var_export($c->compare('a', 'a'));
echo "\n";
$sk = $c->getSortKey('abc');
var_export(is_string($sk) && '' !== $sk);
echo "\n";
var_export(function_exists('collator_compare'));
echo "\n";
if (function_exists('collator_compare')) {
    var_export(collator_compare($c, 'a', 'b'));
    echo "\n";
}
$c2 = Collator::create('en_US');
var_export($c2->compare('a', 'b'));
echo "\n";

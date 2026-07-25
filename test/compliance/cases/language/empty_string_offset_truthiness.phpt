--TEST--
empty()/(bool) on in-bounds string offsets match Zend truthiness (#23071)
--FILE--
<?php
$s = 'a0';
echo 'empty0=' . var_export(empty($s[0]), true) . "\n";
echo 'empty1=' . var_export(empty($s[1]), true) . "\n";
$b = (bool)($s[0]);
echo 'bool0=';
var_export($b);
echo ' typ=' . gettype($b) . "\n";
$t = 'a';
echo 'empty_a=' . var_export(empty($t[0]), true) . "\n";
echo 'empty_neg=' . var_export(empty($s[-1]), true) . "\n";
echo 'empty_neg_a=' . var_export(empty($s[-2]), true) . "\n";
--EXPECT--
empty0=false
empty1=true
bool0=true typ=boolean
empty_a=false
empty_neg=true
empty_neg_a=false

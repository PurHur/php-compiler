--TEST--
JIT: html_entity_decode() (#2472)
--FILE--
<?php
$a = '&lt;x&gt;';
$b = 'Tom &amp; Jerry';
echo html_entity_decode($a), "\n";
echo html_entity_decode($b), "\n";
--EXPECT--
<x>
Tom & Jerry

--TEST--
unset() on an associative array string key (issue #1224)
--FILE--
<?php
$a = ['keep' => 1, 'drop' => 2];
unset($a['drop']);
echo isset($a['keep']) ? 'k' : '-', isset($a['drop']) ? 'd' : '-', "\n";
--EXPECT--
k-

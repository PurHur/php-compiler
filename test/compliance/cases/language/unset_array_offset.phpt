--TEST--
unset() on an array element
--FILE--
<?php
$a = ['k' => 1, 'keep' => 2];
unset($a['k']);
echo isset($a['k']) ? "set\n" : "unset\n";
echo $a['keep'], "\n";
--EXPECT--
unset
2

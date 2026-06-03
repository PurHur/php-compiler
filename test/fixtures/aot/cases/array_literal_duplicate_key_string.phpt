--TEST--
AOT: array literal duplicate string keys — last value wins (#4703)
--FILE--
<?php
$a = ['a' => 1, 'a' => 2];
echo $a['a'], "\n";
?>
--EXPECT--
2

--TEST--
stdlib array_multisort() preserves associative string keys (#10653)
--FILE--
<?php
$a = ['a' => 1, 'm' => 2, 'z' => 3];
array_multisort($a);
echo $a['a'], $a['m'], $a['z'], "\n";
--EXPECT--
123

--TEST--
language: echo array (issue #71, match VM toString)
--FILE--
<?php
$a = ['k' => 1];
echo $a, "\n";
echo $a['k'], "\n";
--EXPECT--
Array
1

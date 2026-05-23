--TEST--
AOT array_flip() int keys to string values
--FILE--
<?php
$b = array(10 => 'x', 20 => 'y');
$g = array_flip($b);
echo $g['x'], "\n";
echo $g['y'], "\n";
--EXPECT--
10
20

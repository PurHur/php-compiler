--TEST--
AOT: array literal duplicate int keys — last value wins (#4703)
--FILE--
<?php
$b = [0 => 'first', 0 => 'last'];
echo $b[0], "\n";
?>
--EXPECT--
last

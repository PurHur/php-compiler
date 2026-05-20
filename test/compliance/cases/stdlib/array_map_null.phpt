--TEST--
stdlib array_map() identity (null callback)
--FILE--
<?php
$copy = array_map(null, ['a', 'b']);
echo $copy[0], $copy[1], "\n";
--EXPECT--
ab

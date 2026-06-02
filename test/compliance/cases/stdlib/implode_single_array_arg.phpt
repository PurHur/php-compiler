--TEST--
stdlib implode() / join() with single array argument (empty glue)
--FILE--
<?php
echo implode(['a', 'b']), "\n";
echo implode([0 => 'x', 1 => 'y']), "\n";
echo join(['1', '2']), "\n";
echo implode(',', array('a', 'b', 'c')), "\n";
--EXPECT--
ab
xy
12
a,b,c

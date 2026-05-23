--TEST--
stdlib join() alias of implode()
--FILE--
<?php
$parts = ['a', 'b', 'c'];
echo join(',', $parts), "\n";
echo join('', ['x', 'y']), "\n";
--EXPECT--
a,b,c
xy

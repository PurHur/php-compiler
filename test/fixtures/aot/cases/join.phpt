--TEST--
AOT: join() alias of implode()
--FILE--
<?php
echo join('|', ['a', 'b', 'c']), "\n";
echo join('', ['x', 'y', 'z']), "\n";
--EXPECT--
a|b|c
xyz

--TEST--
language pre/post decrement (issue #137)
--FILE--
<?php
$i = 2;
echo --$i, $i, $i--;
echo "\n";
--EXPECT--
110

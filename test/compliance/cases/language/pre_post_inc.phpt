--TEST--
language pre/post increment (issue #137)
--FILE--
<?php
$i = 0;
echo ++$i, $i, $i++;
echo "\n";
--EXPECT--
112

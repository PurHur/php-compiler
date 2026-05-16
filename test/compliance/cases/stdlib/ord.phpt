--TEST--
stdlib ord()
--FILE--
<?php
echo ord('A'), "\n";
echo ord(''), "\n";
--EXPECT--
65
0

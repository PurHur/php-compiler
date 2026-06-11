--TEST--
stdlib connection_aborted() returns 0 on CLI (#3242)
--FILE--
<?php
echo connection_aborted(), "\n";
--EXPECT--
0

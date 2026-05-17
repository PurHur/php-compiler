--TEST--
stdlib gettype() for null
--FILE--
<?php
echo gettype(null), "\n";
--EXPECT--
NULL

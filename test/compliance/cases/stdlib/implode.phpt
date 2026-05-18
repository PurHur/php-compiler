--TEST--
stdlib implode() with glue and array
--FILE--
<?php
echo implode(',', array('a', 'b', 'c')), "\n";
echo implode('', array('x', 'y')), "\n";
echo implode('-', array()), "\n";
--EXPECT--
a,b,c
xy


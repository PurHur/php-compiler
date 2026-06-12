--TEST--
stdlib preg_filter() limit argument (issue #4079)
--FILE--
<?php
echo preg_filter('/a/', 'X', 'aaa', 2), "\n";
--EXPECT--
XXa

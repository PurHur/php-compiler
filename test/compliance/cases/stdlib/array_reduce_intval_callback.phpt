--TEST--
stdlib array_reduce() string builtin callback intval (#11057)
--FILE--
<?php
echo array_reduce([1, 2, 3], 'intval'), "\n";
--EXPECT--
0

--TEST--
stdlib strlen()
--FILE--
<?php
echo strlen(''), "\n";
echo strlen('abc'), "\n";
--EXPECT--
0
3

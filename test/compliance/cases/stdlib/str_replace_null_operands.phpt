--TEST--
stdlib str_replace()/str_ireplace() — null search/replace coerces (#11014, ext/standard/string.c)
--FILE--
<?php
echo str_replace(null, 'x', 'abc'), "\n";
echo str_replace('a', null, 'abc'), "\n";
echo str_ireplace(null, 'x', 'abc'), "\n";
echo str_ireplace('a', null, 'abc'), "\n";
--EXPECT--
abc
bc
abc
bc

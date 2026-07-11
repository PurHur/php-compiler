--TEST--
AOT: ucfirst()/lcfirst()/strtoupper()/strtolower() string: named parameter (#16615)
--FILE--
<?php
echo ucfirst(string: 'abc'), "\n";
echo lcfirst(string: 'Abc'), "\n";
echo strtoupper(string: 'abc'), "\n";
echo strtolower(string: 'ABC'), "\n";
--EXPECT--
Abc
abc
ABC
abc

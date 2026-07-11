--TEST--
ucfirst()/lcfirst()/strtoupper()/strtolower()/addslashes()/bin2hex() named string: parameter (#16615)
--FILE--
<?php
echo ucfirst(string: 'abc'), "\n";
echo lcfirst(string: 'Abc'), "\n";
echo strtoupper(string: 'abc'), "\n";
echo strtolower(string: 'ABC'), "\n";
echo addslashes(string: "a'b"), "\n";
echo bin2hex(string: "\x01"), "\n";
--EXPECT--
Abc
abc
ABC
abc
a\'b
01

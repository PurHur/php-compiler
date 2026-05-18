--TEST--
stdlib trim()
--FILE--
<?php
echo trim(''), "\n";
echo trim('abc'), "\n";
echo trim('  hello  '), "\n";
echo trim("\thello\n"), "\n";
echo trim('x'), "\n";
--EXPECT--

abc
hello
hello
x

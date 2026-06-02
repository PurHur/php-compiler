--TEST--
stdlib trim/ltrim/rtrim() optional $characters mask (issue #3709)
--FILE--
<?php
echo ltrim('..hello', '.'), "\n";
echo rtrim('hello..', '.'), "\n";
echo trim('..hello..', '.'), "\n";
echo trim('  hello  '), "\n";
echo trim('abc', ''), "\n";
--EXPECT--
hello
hello
hello
hello
abc

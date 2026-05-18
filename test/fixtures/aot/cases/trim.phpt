--TEST--
AOT: trim() default whitespace mask
--FILE--
<?php
echo trim(''), "\n";
echo trim('  abc  '), "\n";
echo trim("hello\n"), "\n";
--EXPECT--
abc
hello

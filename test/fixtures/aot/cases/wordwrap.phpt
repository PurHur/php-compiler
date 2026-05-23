--TEST--
AOT: wordwrap()
--FILE--
<?php
echo wordwrap('abc def ghi', 5), "\n";
echo wordwrap('12345', 2, '-', true), "\n";
echo wordwrap('wrap me', 4, '|'), "\n";
--EXPECT--
abc
def
ghi
12-34-5
wrap|me

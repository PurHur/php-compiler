--TEST--
stdlib ctype_alnum() ASCII classification (issue #7253)
--FILE--
<?php
echo (int) ctype_alnum('abc'), "\n";
echo (int) ctype_alnum('abc123'), "\n";
echo (int) ctype_alnum(''), "\n";
echo (int) ctype_alnum(' '), "\n";
echo (int) ctype_alnum(97), "\n";
?>
--EXPECT--
1
1
0
0
1

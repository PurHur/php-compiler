--TEST--
AOT: strspn() and strcspn() via libc
--FILE--
<?php
echo strspn('abc123', 'abc'), "\n";
echo strcspn('abc123', '123'), "\n";
--EXPECT--
3
3

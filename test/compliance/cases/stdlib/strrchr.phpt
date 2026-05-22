--TEST--
stdlib strrchr() and strchr() alias
--FILE--
<?php
echo strrchr('abc-def-ghi', '-'), "\n";
echo strchr('abc-def', '-'), "\n";
echo strrchr('path/to/x', '/'), "\n";
--EXPECT--
-ghi
-def
/x

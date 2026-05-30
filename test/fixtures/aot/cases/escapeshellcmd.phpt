--TEST--
AOT: escapeshellcmd() metacharacter escaping
--FILE--
<?php
echo escapeshellcmd('echo hello; rm -rf /'), "\n";
echo escapeshellcmd('a|b'), "\n";
echo escapeshellcmd('plain'), "\n";
--EXPECT--
echo hello\; rm -rf /
a\|b
plain

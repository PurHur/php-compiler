--TEST--
stdlib escapeshellcmd()
--FILE--
<?php
echo escapeshellcmd('echo hello; rm -rf /'), "\n";
echo escapeshellcmd('a|b'), "\n";
echo escapeshellcmd('a&b'), "\n";
echo escapeshellcmd('`x`'), "\n";
echo escapeshellcmd('$HOME'), "\n";
echo escapeshellcmd('a>b'), "\n";
echo escapeshellcmd('a<b'), "\n";
echo escapeshellcmd('plain'), "\n";
echo escapeshellcmd(''), "\n";
--EXPECT--
echo hello\; rm -rf /
a\|b
a\&b
\`x\`
\$HOME
a\>b
a\<b
plain


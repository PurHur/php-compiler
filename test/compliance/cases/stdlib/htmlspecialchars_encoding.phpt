--TEST--
stdlib htmlspecialchars() non-UTF-8 encoding parameter
--FILE--
<?php
echo bin2hex(htmlspecialchars("\xE4", ENT_QUOTES, "ISO-8859-1")), "\n";
echo htmlspecialchars('<x>', ENT_QUOTES, 'Windows-1252'), "\n";
echo htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', false), "\n";
--EXPECT--
e4
&lt;x&gt;
&amp;

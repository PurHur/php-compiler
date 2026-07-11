--TEST--
htmlspecialchars()/htmlentities() invalid UTF-8 bytes (#14739, ext/standard/html.c)
--FILE--
<?php
$s = "a\xFFb";
echo bin2hex(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE)), "\n";
echo var_export(htmlspecialchars($s, ENT_QUOTES), true), "\n";
echo bin2hex(htmlentities($s, ENT_QUOTES | ENT_SUBSTITUTE)), "\n";
echo var_export(htmlentities($s, ENT_QUOTES), true), "\n";
--EXPECT--
61efbfbd62
''
61efbfbd62
''

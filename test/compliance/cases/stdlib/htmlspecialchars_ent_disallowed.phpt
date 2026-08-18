--TEST--
htmlspecialchars()/htmlentities() ENT_DISALLOWED replaces C0/DEL with U+FFFD (#32084, ext/standard/html.c)
--FILE--
<?php
$ctrl = "\x01";
echo bin2hex(htmlspecialchars($ctrl, ENT_DISALLOWED | ENT_HTML5, 'UTF-8')), "\n";
echo bin2hex(htmlentities($ctrl, ENT_DISALLOWED | ENT_HTML5, 'UTF-8')), "\n";
echo bin2hex(htmlspecialchars("a\x01b\x7Fc", ENT_DISALLOWED | ENT_HTML5, 'UTF-8')), "\n";
echo bin2hex(htmlspecialchars($ctrl, ENT_HTML5, 'UTF-8')), "\n";
echo bin2hex(htmlspecialchars("\t", ENT_DISALLOWED | ENT_HTML5, 'UTF-8')), "\n";
echo bin2hex(htmlspecialchars("\u{FDD0}", ENT_DISALLOWED | ENT_HTML5, 'UTF-8')), "\n";
echo bin2hex(htmlspecialchars("\x7F", ENT_DISALLOWED | ENT_XML1, 'UTF-8')), "\n";
--EXPECT--
efbfbd
efbfbd
61efbfbd62efbfbd63
01
09
efbfbd
7f

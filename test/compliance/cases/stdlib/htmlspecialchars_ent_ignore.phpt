--TEST--
htmlspecialchars()/htmlentities() ENT_IGNORE strips invalid UTF-8 (#32063, ext/standard/html.c)
--FILE--
<?php
echo var_export(htmlspecialchars("\xC3\x28", ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
echo var_export(htmlspecialchars("a\xC3\x28b", ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
echo var_export(htmlentities("\xC3\x28", ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
echo var_export(htmlentities("a\xC3\x28b", ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
echo bin2hex(htmlspecialchars("\xC3\x28", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), "\n";
echo var_export(htmlspecialchars("\xC3\xA9", ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
echo var_export(htmlspecialchars("\xC3\x28", ENT_QUOTES, 'UTF-8'), true), "\n";
$dyn = chr(0xC3).chr(0x28);
echo var_export(htmlspecialchars($dyn, ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
--EXPECT--
'('
'a(b'
'('
'a(b'
efbfbd28
'é'
''
'('

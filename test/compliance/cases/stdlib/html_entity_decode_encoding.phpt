--TEST--
stdlib html_entity_decode() optional encoding parameter (#11653, ext/standard/html.c)
--FILE--
<?php
echo html_entity_decode('a', ENT_COMPAT | ENT_HTML401, 'UTF-8'), "\n";
echo bin2hex(html_entity_decode('&eacute;', ENT_COMPAT | ENT_HTML401, 'ISO-8859-1')), "\n";
echo html_entity_decode('&quot;', ENT_QUOTES | ENT_HTML5, 'UTF-8'), "\n";
--EXPECT--
a
e9
"

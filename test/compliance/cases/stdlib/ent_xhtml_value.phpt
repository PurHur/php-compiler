--TEST--
stdlib ENT_XHTML document-type flag is 32 (#24067, ext/standard/html.c)
--FILE--
<?php
echo ENT_XHTML, "\n";
echo ENT_XML1, "\n";
echo ENT_HTML5, "\n";
echo htmlspecialchars("'", ENT_QUOTES | ENT_XHTML), "\n";
echo html_entity_decode('&apos;', ENT_QUOTES | ENT_XHTML), "\n";
--EXPECT--
32
16
48
&apos;
'

--TEST--
JIT: html_entity_decode() ENT_HTML5 named entities (#4130)
--FILE--
<?php
$flags = ENT_QUOTES | ENT_HTML5;
echo html_entity_decode('&apos;', $flags), "\n";
echo html_entity_decode('&nbsp;', ENT_HTML5), "\n";
echo html_entity_decode('&frac12;', ENT_HTML5), "\n";
echo html_entity_decode('&copy;', ENT_HTML5), "\n";
--EXPECT--
'
 
½
©

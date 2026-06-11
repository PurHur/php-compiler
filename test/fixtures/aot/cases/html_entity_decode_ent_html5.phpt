--TEST--
AOT: html_entity_decode() ENT_HTML5 named entities (#4130)
--FILE--
<?php
echo html_entity_decode('&apos;', 51), "\n";
echo html_entity_decode('&nbsp;', 48), "\n";
echo html_entity_decode('&frac12;', 48), "\n";
echo html_entity_decode('&copy;', 48), "\n";
--EXPECT--
'
 
½
©

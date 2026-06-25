--TEST--
stdlib html_entity_decode() numeric character references (#11510, ext/standard/html.c)
--FILE--
<?php
echo html_entity_decode('&#65;', ENT_QUOTES | ENT_HTML5), "\n";
echo html_entity_decode('&#x41;', ENT_QUOTES | ENT_HTML5), "\n";
--EXPECT--
A
A

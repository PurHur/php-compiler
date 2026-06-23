--TEST--
stdlib html_entity_decode() decodes HTML401 named entities by default (#10763, ext/standard/html.c)
--FILE--
<?php
echo bin2hex(html_entity_decode('&nbsp;')), "\n";
echo html_entity_decode('&copy;'), "\n";
--EXPECT--
c2a0
©

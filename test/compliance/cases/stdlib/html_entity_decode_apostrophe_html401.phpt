--TEST--
stdlib html_entity_decode() ENT_HTML401 leaves &apos; unchanged (#13948, ext/standard/html.c)
--FILE--
<?php
echo html_entity_decode('&apos;', ENT_QUOTES | ENT_HTML401), "\n";
--EXPECT--
&apos;

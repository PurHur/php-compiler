--TEST--
JIT: html_entity_decode() after htmlspecialchars_decode() in the same unit (#32064)
--FILE--
<?php
error_reporting(E_ALL);
var_dump(bin2hex(htmlspecialchars_decode('&quot;&amp;&lt;&#039;', ENT_QUOTES)));
var_dump(bin2hex(html_entity_decode('&eacute;', ENT_QUOTES, 'UTF-8')));
var_dump(bin2hex(html_entity_decode('&eacute;', ENT_QUOTES, 'UTF-8')));
var_dump(bin2hex(htmlspecialchars_decode('&quot;&amp;&lt;&#039;', ENT_QUOTES)));
--EXPECT--
string(8) "22263c27"
string(4) "c3a9"
string(4) "c3a9"
string(8) "22263c27"

--TEST--
AOT: html_entity_decode() (#2472)
--FILE--
<?php
echo html_entity_decode('&lt;tag&gt;'), "\n";
echo html_entity_decode(htmlentities('<b>"\'</b>')), "\n";
--EXPECT--
<tag>
<b>"'</b>

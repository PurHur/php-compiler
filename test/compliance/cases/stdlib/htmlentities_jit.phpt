--TEST--
JIT: htmlentities() and html_entity_decode() (#2472)
--FILE--
<?php
echo htmlentities('<x>'), "\n";
echo html_entity_decode(htmlentities('<b>"\'</b>', 3)), "\n";
--EXPECT--
&lt;x&gt;
<b>"'</b>

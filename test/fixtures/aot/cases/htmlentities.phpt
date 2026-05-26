--TEST--
AOT: htmlentities() and html_entity_decode() (#2472)
--FILE--
<?php
echo htmlentities('<tag>'), "\n";
echo html_entity_decode(htmlentities('<b>"\'</b>', 3)), "\n";
--EXPECT--
&lt;tag&gt;
<b>"'</b>

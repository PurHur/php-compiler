--TEST--
stdlib html_entity_decode() (#2472)
--FILE--
<?php
echo html_entity_decode(''), "\n";
echo html_entity_decode('&lt;script&gt;alert(1)&lt;/script&gt;'), "\n";
echo html_entity_decode('Tom &amp; Jerry'), "\n";
echo html_entity_decode('&quot;quoted&quot;'), "\n";
echo html_entity_decode(htmlentities('<a>&"\'</a>')), "\n";
--EXPECT--

<script>alert(1)</script>
Tom & Jerry
"quoted"
<a>&"'</a>

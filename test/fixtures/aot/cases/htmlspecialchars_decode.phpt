--TEST--
AOT: htmlspecialchars_decode() (#2454)
--FILE--
<?php
echo htmlspecialchars_decode('&lt;tag&gt;'), "\n";
echo htmlspecialchars_decode(htmlspecialchars('<b>"\'</b>')), "\n";
--EXPECT--
<tag>
<b>"'</b>

--TEST--
JIT: htmlspecialchars_decode() (#2454)
--FILE--
<?php
echo htmlspecialchars_decode('&lt;x&gt;'), "\n";
echo htmlspecialchars_decode(htmlspecialchars('<b>"\'</b>')), "\n";
--EXPECT--
<x>
<b>"'</b>

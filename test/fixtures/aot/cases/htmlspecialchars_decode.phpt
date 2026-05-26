--TEST--
AOT: htmlspecialchars_decode() round-trip (#2454)
--FILE--
<?php
$s = 'Tom &amp; Jerry &lt;3&gt;';
echo htmlspecialchars_decode($s), "\n";
echo htmlspecialchars_decode(htmlspecialchars('<b>"hi"</b>')), "\n";
--EXPECT--
Tom & Jerry <3>
<b>"hi"</b>

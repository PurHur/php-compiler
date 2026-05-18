--TEST--
stdlib htmlspecialchars()
--FILE--
<?php
echo htmlspecialchars(''), "\n";
echo htmlspecialchars('<script>alert(1)</script>'), "\n";
echo htmlspecialchars('Tom & Jerry'), "\n";
echo htmlspecialchars('"quoted"'), "\n";
--EXPECT--

&lt;script&gt;alert(1)&lt;/script&gt;
Tom &amp; Jerry
&quot;quoted&quot;

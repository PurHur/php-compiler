--TEST--
stdlib htmlentities() (#2472)
--FILE--
<?php
echo htmlentities(''), "\n";
echo htmlentities('<script>alert(1)</script>'), "\n";
echo htmlentities('Tom & Jerry'), "\n";
echo htmlentities('"quoted"'), "\n";
echo htmlentities('<a>&"\'</a>') === htmlspecialchars('<a>&"\'</a>') ? "parity\n" : "diff\n";
--EXPECT--

&lt;script&gt;alert(1)&lt;/script&gt;
Tom &amp; Jerry
&quot;quoted&quot;
parity

--TEST--
stdlib htmlentities() default ENT_COMPAT (#2472)
--FILE--
<?php
echo htmlentities(''), "\n";
echo htmlentities('<script>alert(1)</script>'), "\n";
echo htmlentities('Tom & Jerry'), "\n";
echo htmlentities('"quoted"'), "\n";
echo htmlentities('<a>&"\'</a>', 3), "\n";
--EXPECT--

&lt;script&gt;alert(1)&lt;/script&gt;
Tom &amp; Jerry
&quot;quoted&quot;
&lt;a&gt;&amp;&quot;&#039;&lt;/a&gt;

--TEST--
stdlib htmlspecialchars() ENT_QUOTES with double_encode=false encodes quotes (#11407)
--FILE--
<?php
echo htmlspecialchars('<>&"', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
echo htmlspecialchars('"', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
--EXPECT--
&lt;&gt;&amp;&quot;
&quot;

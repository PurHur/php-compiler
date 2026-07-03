--TEST--
stdlib htmlentities() ENT_COMPAT encodes double quotes only (#9586, ext/standard/html.c)
--FILE--
<?php
echo htmlentities("<a&'>", ENT_COMPAT), "\n";
echo htmlspecialchars_decode("&quot;&#039;", ENT_COMPAT), "\n";
--EXPECT--
&lt;a&amp;'&gt;
"&#039;

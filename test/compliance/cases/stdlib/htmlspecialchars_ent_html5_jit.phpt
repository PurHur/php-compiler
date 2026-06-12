--TEST--
JIT htmlspecialchars()/htmlentities() ENT_HTML5 apostrophe entity (#4958)
--FILE--
<?php
echo htmlspecialchars("'", ENT_QUOTES | ENT_HTML5), "\n";
echo htmlspecialchars("'", ENT_QUOTES), "\n";
echo htmlentities("'", ENT_QUOTES | ENT_HTML5), "\n";
--EXPECT--
&apos;
&#039;
&apos;

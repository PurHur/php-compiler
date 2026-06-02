--TEST--
AOT: htmlspecialchars() null encoding defaults to UTF-8 (#4296)
--FILE--
<?php
echo htmlspecialchars('a&b', ENT_QUOTES | ENT_SUBSTITUTE, null), "\n";
--EXPECT--
a&amp;b

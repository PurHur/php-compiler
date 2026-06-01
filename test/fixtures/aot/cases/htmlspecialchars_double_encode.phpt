--TEST--
AOT: htmlspecialchars() double_encode=false preserves entities (#3786)
--FILE--
<?php
echo htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('&lt;', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('&', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('Tom & Jerry', ENT_QUOTES, 'UTF-8', false), "\n";
--EXPECT--
&amp;
&lt;
&amp;
Tom &amp; Jerry

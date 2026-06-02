--TEST--
stdlib htmlspecialchars() double_encode=false preserves entities (#3786)
--FILE--
<?php
echo htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('&lt;', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('&', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', true), "\n";
echo htmlspecialchars('Tom & Jerry', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('&#38;', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars('&foo;', ENT_QUOTES, 'UTF-8', false), "\n";
--EXPECT--
&amp;
&lt;
&amp;
&amp;amp;
Tom &amp; Jerry
&#38;
&amp;foo;

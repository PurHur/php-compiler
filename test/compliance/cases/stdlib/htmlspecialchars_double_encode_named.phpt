--TEST--
stdlib htmlspecialchars() double_encode: named parameter (#10471, ext/standard/html.c)
--FILE--
<?php
echo htmlspecialchars('&amp;', double_encode: false), "\n";
echo htmlspecialchars('&amp;', double_encode: true), "\n";
?>
--EXPECT--
&amp;
&amp;amp;

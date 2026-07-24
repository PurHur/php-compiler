--TEST--
AOT: htmlspecialchars() literal non-empty via HtmlspecialcharsJitHelper (#22845)
--FILE--
<?php
echo htmlspecialchars('MiniWebApp');
?>
--EXPECT--
MiniWebApp

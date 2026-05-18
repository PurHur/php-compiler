--TEST--
Integration: str_replace, nl2br, htmlspecialchars
--FILE--
<?php
$msg = "Tom & Jerry\n";
$msg = str_replace('&', 'and', $msg);
echo htmlspecialchars(nl2br($msg)), "\n";
--EXPECT--
Tom and Jerry&lt;br /&gt;

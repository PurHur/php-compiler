--TEST--
stdlib urlencode() and rawurlencode()
--FILE--
<?php
echo urlencode("a b"), "\n";
echo rawurlencode("a b"), "\n";
$name = 'a&b';
echo '<a href="?name=' . urlencode($name) . '">', "\n";
--EXPECT--
a+b
a%20b
<a href="?name=a%26b">

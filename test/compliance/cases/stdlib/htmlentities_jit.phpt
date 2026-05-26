--TEST--
JIT: htmlentities() (#2472)
--FILE--
<?php
$a = '<x>';
$b = 'Tom & Jerry';
echo htmlentities($a), "\n";
echo htmlentities($b), "\n";
--EXPECT--
&lt;x&gt;
Tom &amp; Jerry

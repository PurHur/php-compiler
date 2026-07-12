--TEST--
stdlib strspn()/strcspn() — null haystack coerces to 0 when non-strict JIT (#18264)
--JIT--
--FILE--
<?php
echo strspn(null, 'abc'), "\n";
echo strcspn(null, 'abc'), "\n";
?>
--EXPECT--
0
0

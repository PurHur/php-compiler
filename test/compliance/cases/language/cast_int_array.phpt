--TEST--
Language: (int) cast on array — Zend cast (#5272)
--FILE--
<?php
echo (int) ([]), "\n";
echo (int) [1, 2], "\n";
?>
--EXPECT--
0
1

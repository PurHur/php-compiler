--TEST--
Language: (float) cast on array — Zend cast (#5287)
--FILE--
<?php
echo (float) ([]), "\n";
echo (float) [1, 2], "\n";
?>
--EXPECT--
0
1

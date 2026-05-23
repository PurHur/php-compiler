--TEST--
AOT preg_grep() filters paths (issue #1180)
--FILE--
<?php
$paths = ['one.txt', 'two.php', 'three.php'];
$php = preg_grep('#\.php$#', $paths);
echo count($php), "\n";
--EXPECT--
2

--TEST--
stdlib preg_grep() PREG_GREP_INVERT (issue #1180)
--FILE--
<?php
$paths = ['a.php', 'b.txt', 'c.php'];
$nonPhp = preg_grep('#\.php$#', $paths, PREG_GREP_INVERT);
echo count($nonPhp), "\n";
foreach ($nonPhp as $path) {
    echo $path, "\n";
}
--EXPECT--
1
b.txt

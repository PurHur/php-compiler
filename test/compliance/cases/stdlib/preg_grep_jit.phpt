--TEST--
stdlib preg_grep() JIT filters array by pattern (issue #1180)
--FILE--
<?php
$paths = ['alpha.txt', 'beta.php', 'gamma.php'];
$php = preg_grep('#\.php$#', $paths);
echo count($php), "\n";
foreach ($php as $path) {
    echo $path, "\n";
}
--EXPECT--
2
beta.php
gamma.php

--TEST--
stdlib preg_grep() filters array by pattern (issue #1180)
--FILE--
<?php
$paths = ['lib/Foo.php', 'lib/Bar.php', 'test/config.php', 'README.md'];
$php = preg_grep('#\.php$#', $paths);
echo count($php), "\n";
foreach ($php as $path) {
    echo $path, "\n";
}
--EXPECT--
3
lib/Foo.php
lib/Bar.php
test/config.php

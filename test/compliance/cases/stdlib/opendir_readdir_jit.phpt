--TEST--
JIT/AOT: opendir/readdir/closedir via __compiler_opendir (issue #3235)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$dh = opendir($dir);
$names = [];
$file = readdir($dh);
while ($file !== false) {
    $names[] = $file;
    $file = readdir($dh);
}
rewinddir($dh);
$after = readdir($dh);
$tail = readdir($dh);
while ($tail !== false) {
    $tail = readdir($dh);
}
closedir($dh);
echo in_array('a.php', $names, true) ? 'has_a' : 'no_a', "\n";
echo in_array('readme.txt', $names, true) ? 'has_txt' : 'no_txt', "\n";
echo ($after !== false) ? 'rewound' : 'eof', "\n";
--EXPECT--
has_a
has_txt
rewound

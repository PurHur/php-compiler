--TEST--
JIT/AOT: opendir/readdir/closedir via __compiler_opendir (issue #3235)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$dh = opendir($dir);
$names = [];
while (true) {
    $file = readdir($dh);
    if (gettype($file) !== 'string') {
        break;
    }
    $names[] = $file;
}
rewinddir($dh);
$after = readdir($dh);
while (true) {
    $tail = readdir($dh);
    if (gettype($tail) !== 'string') {
        break;
    }
}
closedir($dh);
echo in_array('a.php', $names, true) ? 'has_a' : 'no_a', "\n";
echo in_array('readme.txt', $names, true) ? 'has_txt' : 'no_txt', "\n";
echo (gettype($after) === 'string') ? 'rewound' : 'eof', "\n";
--EXPECT--
has_a
has_txt
rewound

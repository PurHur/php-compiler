--TEST--
stdlib opendir/readdir/closedir/rewinddir — directory iteration (#3235)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$dh = opendir($dir);
echo is_resource($dh) ? 'resource' : 'not', "\n";
$names = [];
while (true) {
    $file = readdir($dh);
    if (gettype($file) !== 'string') {
        break;
    }
    $names[] = $file;
}
closedir($dh);
sort($names);
echo in_array('.', $names, true) ? 'dot' : 'no_dot', "\n";
echo in_array('..', $names, true) ? 'dotdot' : 'no_dotdot', "\n";
echo in_array('a.php', $names, true) ? 'has_a' : 'no_a', "\n";
echo in_array('readme.txt', $names, true) ? 'has_txt' : 'no_txt', "\n";
echo @opendir('/nonexistent/path/for/php-compiler') === false ? 'false' : 'ok', "\n";
--EXPECT--
resource
dot
dotdot
has_a
has_txt
false

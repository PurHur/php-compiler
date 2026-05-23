--TEST--
stdlib filetype()
--FILE--
<?php
$linkBase = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $linkBase . '/link';
$file = $linkBase . '/target.txt';
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
echo filetype($link), "\n";
echo filetype($file), "\n";
echo filetype($dir), "\n";
$gone = filetype('/no/such/phpc-filetype-path');
if ($gone == false) {
    echo 'gone', "\n";
} else {
    echo 'bad', "\n";
}
--EXPECT--
link
file
dir
gone

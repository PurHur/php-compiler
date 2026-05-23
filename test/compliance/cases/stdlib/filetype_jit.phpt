--TEST--
JIT: filetype() via lstat st_mode
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$file = $base . '/target.txt';
echo filetype($link), "\n";
echo filetype($file), "\n";
echo filetype($base), "\n";
if (!file_exists('/no/such/phpc-filetype-path')) {
    echo 'gone', "\n";
} else {
    echo 'badgone', "\n";
}
--EXPECT--
link
file
dir
gone

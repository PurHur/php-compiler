--TEST--
stdlib filetype()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$file = $base . '/target.txt';
echo filetype($link), "\n";
echo filetype($file), "\n";
echo filetype($base), "\n";
if (filetype('/no/such/phpc-filetype-path') === false) {
    echo 'gone', "\n";
} else {
    echo 'badgone', "\n";
}
--EXPECT--
link
file
dir
gone

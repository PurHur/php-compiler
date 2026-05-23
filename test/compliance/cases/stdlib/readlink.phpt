--TEST--
stdlib readlink()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$file = $base . '/target.txt';
echo readlink($link), "\n";
if (!is_link($file)) {
    echo 'notlink', "\n";
} else {
    echo 'bad', "\n";
}
if (!is_link('/no/such/phpc-readlink-path')) {
    echo 'gone', "\n";
} else {
    echo 'badgone', "\n";
}
--EXPECT--
target.txt
notlink
gone

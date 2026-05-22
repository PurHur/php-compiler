--TEST--
JIT: is_link() via lstat S_IFLNK
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$file = $base . '/target.txt';
if (is_link($link)) {
    echo 'link', "\n";
} else {
    echo 'nolink', "\n";
}
if (is_link($file)) {
    echo 'bad', "\n";
} else {
    echo 'notlink', "\n";
}
if (is_link('/no/such/phpc-link-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
link
notlink
gone

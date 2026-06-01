--TEST--
AOT: opendir/readdir/closedir (issue #3235)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases';
$dh = opendir($dir);
$count = 0;
while (true) {
    $entry = readdir($dh);
    if (gettype($entry) !== 'string') {
        break;
    }
    $count++;
}
closedir($dh);
echo $count > 0 ? 'ok' : 'empty';
--EXPECT--
ok

--TEST--
AOT: opendir/readdir/closedir (issue #3235)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases';
$dh = opendir($dir);
$count = 0;
$entry = readdir($dh);
while ($entry !== false) {
    $count++;
    $entry = readdir($dh);
}
closedir($dh);
echo $count > 0 ? 'ok' : 'empty';
--EXPECT--
ok

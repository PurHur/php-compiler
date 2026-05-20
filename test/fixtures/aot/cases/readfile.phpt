--TEST--
AOT: readfile() streams file bytes to stdout
--FILE--
<?php
$path = 'test/fixtures/aot/cases/readfile_asset.txt';
$n = readfile($path);
echo "\n", $n, "\n";
--EXPECT--
asset-bytes
11

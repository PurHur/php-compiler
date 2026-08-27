--TEST--
AOT: ZipArchive CREATE roundtrip open/addFromString/close/getFromName (#35424)
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
--FILE--
<?php
$path = sys_get_temp_dir().'/phpc_zip_fix_35424_'.getmypid().'.zip';
@unlink($path);
$z = new ZipArchive();
var_export($z->open($path, ZipArchive::CREATE));
echo "\n";
var_export($z->addFromString('a.txt', 'hello'));
echo "\n";
var_export($z->close());
echo "\n";
$z2 = new ZipArchive();
$z2->open($path);
var_export($z2->getFromName('a.txt'));
echo "\n";
@unlink($path);
--EXPECT--
true
true
true
'hello'

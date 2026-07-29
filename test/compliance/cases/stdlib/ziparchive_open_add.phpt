--TEST--
stdlib ZipArchive open/addFromString/getFromName round-trip (#3337, ext/zip/php_zip.c)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
    die('skip zip withheld (#18137/#25010)');
}
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
--FILE--
<?php
declare(strict_types=1);

$path = sys_get_temp_dir() . '/phpc_zip_' . bin2hex(random_bytes(4)) . '.zip';
$z = new ZipArchive();
var_export(method_exists($z, 'open'));
echo "\n";
var_export($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
echo "\n";
var_export($z->addFromString('hello.txt', 'hi'));
echo "\n";
var_export($z->close());
echo "\n";
$z2 = new ZipArchive();
var_export($z2->open($path));
echo "\n";
var_export($z2->getFromName('hello.txt'));
echo "\n";
@unlink($path);
--EXPECT--
true
true
true
true
true
'hi'

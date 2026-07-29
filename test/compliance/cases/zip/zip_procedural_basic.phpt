--TEST--
zip procedural API round-trip (ext/zip/php_zip.c, #6370)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
    die('skip zip withheld (#18137/#25010)');
}
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
--FILE--
<?php
$tmpdir = sys_get_temp_dir() . '/phpc_zip_proc_' . bin2hex(random_bytes(4));
mkdir($tmpdir, 0777, true);
$archive = $tmpdir . '/test.zip';
$payload = 'zip procedural payload';

$zip = new ZipArchive();
$zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('hello.txt', $payload);
$zip->close();

echo function_exists('zip_open') ? '1' : '0', "\n";

$zh = zip_open($archive);
echo is_resource($zh) ? '1' : '0', "\n";
echo get_resource_type($zh), "\n";

$entry = zip_read($zh);
echo is_resource($entry) ? '1' : '0', "\n";
echo get_resource_type($entry), "\n";
echo zip_entry_name($entry), "\n";
echo zip_entry_filesize($entry), "\n";
echo zip_entry_compressedsize($entry), "\n";
echo zip_entry_compressionmethod($entry), "\n";
echo zip_entry_open($zh, $entry) ? '1' : '0', "\n";
echo zip_entry_read($entry, strlen($payload)), "\n";
echo zip_entry_close($entry) ? '1' : '0', "\n";
echo zip_read($zh) === false ? '1' : '0', "\n";
echo zip_close($zh) ? '1' : '0', "\n";

@unlink($archive);
@rmdir($tmpdir);
?>
--EXPECT--
1
1
Zip Archive
1
Zip Entry
hello.txt
22
22
stored
1
zip procedural payload
1
1
1

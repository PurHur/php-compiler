--TEST--
zip_entry_compressedsize / zip_entry_compressionmethod (ext/zip/php_zip.c, #20485)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
    die('skip zip withheld (#18137/#25010)');
}
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
--FILE--
<?php
$tmpdir = sys_get_temp_dir() . '/phpc_zip_meta_' . bin2hex(random_bytes(4));
mkdir($tmpdir, 0777, true);
$archive = $tmpdir . '/test.zip';
$payload = 'zip entry meta payload';

$zip = new ZipArchive();
$zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('meta.txt', $payload);
$zip->close();

echo function_exists('zip_entry_compressedsize') ? '1' : '0', "\n";
echo function_exists('zip_entry_compressionmethod') ? '1' : '0', "\n";

$zh = zip_open($archive);
$entry = zip_read($zh);
echo zip_entry_name($entry), "\n";
echo zip_entry_filesize($entry), "\n";
$comp = zip_entry_compressedsize($entry);
echo is_int($comp) && $comp >= 0 ? '1' : '0', "\n";
echo $comp, "\n";
$method = zip_entry_compressionmethod($entry);
echo is_string($method) && $method !== '' ? '1' : '0', "\n";
echo $method, "\n";
echo zip_close($zh) ? '1' : '0', "\n";

@unlink($archive);
@rmdir($tmpdir);
?>
--EXPECT--
1
1
meta.txt
22
1
22
1
stored
1

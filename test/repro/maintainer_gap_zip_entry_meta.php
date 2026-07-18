<?php
/**
 * #20485 — zip_entry_compressedsize / zip_entry_compressionmethod registration + meta.
 */
$tmpdir = sys_get_temp_dir() . '/phpc_zip_meta_' . bin2hex(random_bytes(4));
mkdir($tmpdir, 0777, true);
$archive = $tmpdir . '/test.zip';
$payload = 'zip entry meta payload';

$zip = new ZipArchive();
$zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('meta.txt', $payload);
$zip->close();

echo 'zip_entry_name=', function_exists('zip_entry_name') ? 'Y' : 'N', "\n";
echo 'zip_entry_filesize=', function_exists('zip_entry_filesize') ? 'Y' : 'N', "\n";
echo 'zip_entry_compressedsize=', function_exists('zip_entry_compressedsize') ? 'Y' : 'N', "\n";
echo 'zip_entry_compressionmethod=', function_exists('zip_entry_compressionmethod') ? 'Y' : 'N', "\n";

$zh = zip_open($archive);
$entry = zip_read($zh);
echo 'compressedsize=', zip_entry_compressedsize($entry), "\n";
echo 'compressionmethod=', zip_entry_compressionmethod($entry), "\n";
echo 'filesize=', zip_entry_filesize($entry), "\n";
zip_close($zh);

@unlink($archive);
@rmdir($tmpdir);

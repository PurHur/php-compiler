--TEST--
ZipArchive::setArchiveFlag/getArchiveFlag + AFL_* (#21831, ext/zip/php_zip.c)
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
--FILE--
<?php
declare(strict_types=1);

echo 'AFL_RDONLY=', ZipArchive::AFL_RDONLY, "\n";
echo 'AFL_WANT=', ZipArchive::AFL_WANT_TORRENTZIP, "\n";
echo 'FL_UNCHANGED=', ZipArchive::FL_UNCHANGED, "\n";

$path = sys_get_temp_dir().'/phpc_zip_afl_c_'.getmypid().'.zip';
@unlink($path);
$zip = new ZipArchive();
$zip->open($path, 9);
echo 'init=', $zip->getArchiveFlag(ZipArchive::AFL_RDONLY), "\n";
echo 'set=', (int) $zip->setArchiveFlag(ZipArchive::AFL_RDONLY, 1), "\n";
echo 'get=', $zip->getArchiveFlag(ZipArchive::AFL_RDONLY), "\n";
echo 'writable=', (int) $zip->isWritable(), "\n";
echo 'clear=', (int) $zip->setArchiveFlag(ZipArchive::AFL_RDONLY, 0), "\n";
echo 'still=', $zip->getArchiveFlag(ZipArchive::AFL_RDONLY), "\n";
echo 'unchanged=', $zip->getArchiveFlag(ZipArchive::AFL_RDONLY, ZipArchive::FL_UNCHANGED), "\n";
echo 'want=', (int) $zip->setArchiveFlag(ZipArchive::AFL_WANT_TORRENTZIP, 1), "\n";
echo 'wantget=', $zip->getArchiveFlag(ZipArchive::AFL_WANT_TORRENTZIP), "\n";
try {
    $zip->setArchiveFlag(ZipArchive::AFL_RDONLY);
    echo "arity1-uncaught\n";
} catch (ArgumentCountError $e) {
    echo "arity1=ok\n";
}
$zip->close();
@unlink($path);
?>
--EXPECT--
AFL_RDONLY=2
AFL_WANT=8
FL_UNCHANGED=8
init=0
set=1
get=1
writable=0
clear=0
still=1
unchanged=0
want=1
wantget=1
arity1=ok

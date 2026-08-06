--TEST--
ZipArchive::CREATE/OVERWRITE/ER_*/CM_*/EM_* accessible + defined() (#28110)
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
    die('skip zip withheld (#18137/#25010)');
}
?>
--FILE--
<?php
echo 'CREATE=', (int) ZipArchive::CREATE, "\n";
echo 'OVERWRITE=', (int) ZipArchive::OVERWRITE, "\n";
echo 'ER_OK=', (int) ZipArchive::ER_OK, "\n";
echo 'CM_DEFAULT=', (int) ZipArchive::CM_DEFAULT, "\n";
echo 'EM_AES_128=', (int) ZipArchive::EM_AES_128, "\n";
echo 'LIBZIP_VERSION=', ZipArchive::LIBZIP_VERSION, "\n";
echo 'defined_CREATE=', defined('ZipArchive::CREATE') ? 'yes' : 'no', "\n";
echo 'defined_OVERWRITE=', defined('ZipArchive::OVERWRITE') ? 'yes' : 'no', "\n";
echo 'defined_ER_OK=', defined('ZipArchive::ER_OK') ? 'yes' : 'no', "\n";
echo 'constant_CREATE=', (int) constant('ZipArchive::CREATE'), "\n";
$path = sys_get_temp_dir() . '/phpc_zip_consts_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$opened = $z->open($path, ZipArchive::CREATE);
echo 'open_CREATE=', var_export($opened, true), "\n";
if (true === $opened || is_int($opened)) {
    $z->close();
}
@unlink($path);
?>
--EXPECT--
CREATE=1
OVERWRITE=8
ER_OK=0
CM_DEFAULT=-1
EM_AES_128=257
LIBZIP_VERSION=1.11.3
defined_CREATE=yes
defined_OVERWRITE=yes
defined_ER_OK=yes
constant_CREATE=1
open_CREATE=true

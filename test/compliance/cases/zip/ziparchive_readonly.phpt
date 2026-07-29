--TEST--
ZipArchive isWritable / setReadOnly (#20412)
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
echo 'isWritable=', method_exists('ZipArchive', 'isWritable') ? 'yes' : 'no', "\n";
echo 'setReadOnly=', method_exists('ZipArchive', 'setReadOnly') ? 'yes' : 'no', "\n";
echo 'ER_RDONLY=', (int) ZipArchive::ER_RDONLY, "\n";

$path = sys_get_temp_dir() . '/phpc_zip_ro_' . getmypid() . '.zip';
@unlink($path);
$zip = new ZipArchive();
$zip->open($path, 9);
echo 'writable=', var_export($zip->isWritable(), true), "\n";
$zip->addFromString('a.txt', 'hello');
echo 'setRO=', var_export($zip->setReadOnly(true), true), "\n";
echo 'writable2=', var_export($zip->isWritable(), true), "\n";
echo 'addAfterRO=', var_export($zip->addFromString('b.txt', 'x'), true), "\n";
echo 'status=', $zip->status, ' msg=', $zip->getStatusString(), "\n";
echo 'setROfalse=', var_export($zip->setReadOnly(false), true), "\n";
echo 'writable3=', var_export($zip->isWritable(), true), "\n";
echo 'addAfterRW=', var_export($zip->addFromString('b.txt', 'x'), true), "\n";
$zip->close();
@unlink($path);
?>
--EXPECT--
isWritable=yes
setReadOnly=yes
ER_RDONLY=25
writable=true
setRO=true
writable2=false
addAfterRO=false
status=25 msg=Read-only archive
setROfalse=true
writable3=true
addAfterRW=true

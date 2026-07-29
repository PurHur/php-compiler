--TEST--
ZipArchive::statName/setPassword/setEncryptionName + EM_* (#19873, ext/zip/php_zip.c)
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
echo 'EM_NONE=', ZipArchive::EM_NONE, "\n";
echo 'EM_AES_128=', ZipArchive::EM_AES_128, "\n";
echo 'EM_AES_192=', ZipArchive::EM_AES_192, "\n";
echo 'EM_AES_256=', ZipArchive::EM_AES_256, "\n";
echo 'methods=', (int) method_exists('ZipArchive', 'statName'),
    (int) method_exists('ZipArchive', 'setPassword'),
    (int) method_exists('ZipArchive', 'setEncryptionName'), "\n";

$path = sys_get_temp_dir() . '/phpc_zip_stat_' . getmypid() . '.zip';
@unlink($path);

$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
echo 'setPassword=', var_export($zip->setPassword('secret'), true), "\n";
echo 'setEnc=', var_export($zip->setEncryptionName('a.txt', ZipArchive::EM_AES_128), true), "\n";
echo 'setEncMiss=', var_export($zip->setEncryptionName('missing.txt', ZipArchive::EM_AES_128), true), "\n";
$st = $zip->statName('a.txt');
echo 'name=', $st['name'], ' size=', $st['size'], ' enc=', $st['encryption_method'], "\n";
echo 'miss=', var_export($zip->statName('nope.txt'), true), "\n";
$zip->close();

$zip2 = new ZipArchive();
$zip2->open($path);
$st2 = $zip2->statName('a.txt');
echo 'reopen name=', $st2['name'], ' size=', $st2['size'], "\n";
$zip2->close();
@unlink($path);
?>
--EXPECT--
EM_NONE=0
EM_AES_128=257
EM_AES_192=258
EM_AES_256=259
methods=111
setPassword=true
setEnc=true
setEncMiss=false
name=a.txt size=5 enc=257
miss=false
reopen name=a.txt size=5

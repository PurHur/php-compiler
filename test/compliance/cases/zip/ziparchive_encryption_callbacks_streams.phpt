--TEST--
ZipArchive isEncryptionMethodSupported/register*Callback/getStream*/clearError/setEncryptionIndex (#20378)
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
$need = [
    'isEncryptionMethodSupported',
    'registerProgressCallback',
    'registerCancelCallback',
    'getStreamIndex',
    'getStreamName',
    'clearError',
    'setEncryptionIndex',
];
$bits = '';
foreach ($need as $m) {
    $bits .= method_exists('ZipArchive', $m) ? '1' : '0';
}
echo "methods=$bits\n";
echo 'encNone=', (int) ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_NONE), "\n";
echo 'encAes=', (int) ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_128), "\n";
echo 'encUnknown=', (int) ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_UNKNOWN), "\n";

$GLOBALS['phpc_zip_20378_progress'] = 0;
$GLOBALS['phpc_zip_20378_cancel'] = 0;
function phpc_zip_20378_on_progress($state) {
    $GLOBALS['phpc_zip_20378_progress']++;
}
function phpc_zip_20378_on_cancel() {
    $GLOBALS['phpc_zip_20378_cancel']++;
    return 0;
}

$path = sys_get_temp_dir() . '/phpc_zip_enc_' . getmypid() . '.zip';
@unlink($path);

$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
echo 'setEncIdx=', var_export($zip->setEncryptionIndex(0, ZipArchive::EM_AES_128, 'secret'), true), "\n";
$st = $zip->statIndex(0);
echo 'enc=', $st['encryption_method'], "\n";

echo 'regProgress=', var_export($zip->registerProgressCallback(0.5, 'phpc_zip_20378_on_progress'), true), "\n";
echo 'regCancel=', var_export($zip->registerCancelCallback('phpc_zip_20378_on_cancel'), true), "\n";

$stream = $zip->getStreamIndex(0);
echo 'streamIdx=', (is_resource($stream) ? 'yes' : 'no'), ' data=', stream_get_contents($stream), "\n";
fclose($stream);
$stream2 = $zip->getStreamName('a.txt');
echo 'streamName=', (is_resource($stream2) ? 'yes' : 'no'), ' data=', stream_get_contents($stream2), "\n";
fclose($stream2);

$zip->status = ZipArchive::ER_NOENT;
echo 'statusBefore=', $zip->status, "\n";
$zip->clearError();
echo 'statusAfter=', $zip->status, "\n";

echo 'close=', var_export($zip->close(), true), "\n";
echo 'progressHits=', $GLOBALS['phpc_zip_20378_progress'], ' cancelHits=', $GLOBALS['phpc_zip_20378_cancel'], "\n";
@unlink($path);
?>
--EXPECT--
methods=1111111
encNone=1
encAes=1
encUnknown=0
setEncIdx=true
enc=257
regProgress=true
regCancel=true
streamIdx=yes data=hello
streamName=yes data=hello
statusBefore=9
statusAfter=0
close=true
progressHits=1 cancelHits=1

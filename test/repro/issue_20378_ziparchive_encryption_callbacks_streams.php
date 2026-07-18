<?php
/**
 * Repro #20378 — ZipArchive encryption/callbacks/getStreamIndex/Name/clearError.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20378_ziparchive_encryption_callbacks_streams.php
 */
$need = [
    'isEncryptionMethodSupported',
    'registerProgressCallback',
    'registerCancelCallback',
    'getStreamIndex',
    'getStreamName',
    'clearError',
    'setEncryptionIndex',
];
foreach ($need as $m) {
    echo $m, '=', method_exists(ZipArchive::class, $m) ? 'yes' : 'no', "\n";
}
echo 'encAes=', (int) ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_128), "\n";

$GLOBALS['phpc_zip_20378_hits'] = 0;
function phpc_zip_20378_progress($state) {
    $GLOBALS['phpc_zip_20378_hits']++;
}

$path = sys_get_temp_dir() . '/phpc_zip_20378_' . getmypid() . '.zip';
@unlink($path);
$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
var_export($zip->setEncryptionIndex(0, ZipArchive::EM_AES_128));
echo "\n";
$zip->registerProgressCallback(0.5, 'phpc_zip_20378_progress');
$stream = $zip->getStreamName('a.txt');
echo 'stream=', stream_get_contents($stream), "\n";
fclose($stream);
$zip->clearError();
$zip->close();
echo 'hits=', $GLOBALS['phpc_zip_20378_hits'], "\n";
@unlink($path);

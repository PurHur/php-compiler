<?php
/**
 * Repro #20363 — ZipArchive mtime / external attributes / compression APIs.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20363_ziparchive_mtime_attrs_compression.php
 */
$need = [
    'setMtimeName', 'setMtimeIndex',
    'setExternalAttributesName', 'setExternalAttributesIndex',
    'getExternalAttributesName', 'getExternalAttributesIndex',
    'setCompressionName', 'setCompressionIndex',
    'isCompressionMethodSupported',
];
foreach ($need as $m) {
    echo $m, '=', method_exists(ZipArchive::class, $m) ? 'yes' : 'no', "\n";
}

$path = sys_get_temp_dir() . '/phpc_zip_20363_' . getmypid() . '.zip';
@unlink($path);
$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
$ts = 1700000000;
var_export($zip->setMtimeName('a.txt', $ts));
echo "\n";
var_export($zip->setCompressionName('a.txt', ZipArchive::CM_STORE));
echo "\n";
var_export($zip->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 0));
echo "\n";
$st = $zip->statName('a.txt');
echo 'mtime=', $st['mtime'], ' comp=', $st['comp_method'], "\n";
echo 'supp=', (int) ZipArchive::isCompressionMethodSupported(ZipArchive::CM_STORE), "\n";
$zip->close();
@unlink($path);

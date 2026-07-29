--TEST--
ZipArchive setMtime*/setExternalAttributes*/setCompression*/isCompressionMethodSupported (#20363)
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
    'setMtimeName', 'setMtimeIndex',
    'setExternalAttributesName', 'setExternalAttributesIndex',
    'getExternalAttributesName', 'getExternalAttributesIndex',
    'setCompressionName', 'setCompressionIndex',
    'isCompressionMethodSupported',
];
$bits = '';
foreach ($need as $m) {
    $bits .= method_exists('ZipArchive', $m) ? '1' : '0';
}
echo "methods=$bits\n";
echo 'CM_STORE=', ZipArchive::CM_STORE, ' CM_DEFAULT=', ZipArchive::CM_DEFAULT, "\n";
echo 'OPSYS_UNIX=', ZipArchive::OPSYS_UNIX, ' OPSYS_DEFAULT=', ZipArchive::OPSYS_DEFAULT, "\n";
echo 'suppStore=', (int) ZipArchive::isCompressionMethodSupported(ZipArchive::CM_STORE), "\n";
echo 'suppDeflate=', (int) ZipArchive::isCompressionMethodSupported(ZipArchive::CM_DEFLATE), "\n";
echo 'suppDefault=', (int) ZipArchive::isCompressionMethodSupported(ZipArchive::CM_DEFAULT), "\n";

$path = sys_get_temp_dir() . '/phpc_zip_mtime_' . getmypid() . '.zip';
@unlink($path);

$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
$ts = 1700000000;
echo 'setMtime=', var_export($zip->setMtimeName('a.txt', $ts), true), "\n";
echo 'setMtimeMiss=', var_export($zip->setMtimeName('nope.txt', $ts), true), "\n";
echo 'setComp=', var_export($zip->setCompressionName('a.txt', ZipArchive::CM_STORE), true), "\n";
echo 'setCompBad=', var_export($zip->setCompressionName('a.txt', ZipArchive::CM_DEFLATE), true), "\n";
echo 'setAttr=', var_export($zip->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 33188), true), "\n";
$opsys = -1;
$attr = -1;
echo 'getAttr=', var_export($zip->getExternalAttributesName('a.txt', $opsys, $attr), true), "\n";
echo 'opsys=', $opsys, ' attr=', $attr, "\n";
$st = $zip->statName('a.txt');
echo 'mtime=', $st['mtime'], ' comp=', $st['comp_method'], "\n";
$ts2 = 1700000060;
echo 'setMtimeIdx=', var_export($zip->setMtimeIndex(0, $ts2), true), "\n";
$st2 = $zip->statIndex(0);
echo 'mtimeIdx=', $st2['mtime'], "\n";
$zip->close();

$zip2 = new ZipArchive();
$zip2->open($path);
$st3 = $zip2->statName('a.txt');
echo 'reopen mtime=', $st3['mtime'], "\n";
$opsys2 = -1;
$attr2 = -1;
echo 'reopen getAttr=', var_export($zip2->getExternalAttributesIndex(0, $opsys2, $attr2), true), "\n";
echo 'reopen opsys=', $opsys2, "\n";
$zip2->close();
@unlink($path);
?>
--EXPECT--
methods=111111111
CM_STORE=0 CM_DEFAULT=-1
OPSYS_UNIX=3 OPSYS_DEFAULT=3
suppStore=1
suppDeflate=0
suppDefault=1
setMtime=true
setMtimeMiss=false
setComp=true
setCompBad=false
setAttr=true
getAttr=true
opsys=3 attr=33188
mtime=1700000000 comp=0
setMtimeIdx=true
mtimeIdx=1700000060
reopen mtime=1700000060
reopen getAttr=true
reopen opsys=3

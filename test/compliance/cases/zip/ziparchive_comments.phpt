--TEST--
ZipArchive set/getComment* + set/getArchiveComment round-trip (#20386)
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
    'setCommentName',
    'setCommentIndex',
    'getCommentName',
    'getCommentIndex',
    'setArchiveComment',
    'getArchiveComment',
];
$bits = '';
foreach ($need as $m) {
    $bits .= method_exists('ZipArchive', $m) ? '1' : '0';
}
echo "methods=$bits\n";

$path = sys_get_temp_dir() . '/phpc_zip_comment_' . getmypid() . '.zip';
@unlink($path);

$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
$zip->addFromString('b.txt', 'world');
echo 'setName=', var_export($zip->setCommentName('a.txt', 'entry-a'), true), "\n";
echo 'setIdx=', var_export($zip->setCommentIndex(1, 'entry-b'), true), "\n";
echo 'setMiss=', var_export($zip->setCommentName('nope.txt', 'x'), true), "\n";
echo 'setArch=', var_export($zip->setArchiveComment('archive-note'), true), "\n";
echo 'getName=', var_export($zip->getCommentName('a.txt'), true), "\n";
echo 'getIdx=', var_export($zip->getCommentIndex(1), true), "\n";
echo 'getArch=', var_export($zip->getArchiveComment(), true), "\n";
echo 'getB=', var_export($zip->getCommentName('b.txt'), true), "\n";
echo 'close=', var_export($zip->close(), true), "\n";

$zip2 = new ZipArchive();
$zip2->open($path);
echo 'reopen name=', var_export($zip2->getCommentName('a.txt'), true), "\n";
echo 'reopen idx=', var_export($zip2->getCommentIndex(1), true), "\n";
echo 'reopen arch=', var_export($zip2->getArchiveComment(), true), "\n";
echo 'clearName=', var_export($zip2->setCommentName('a.txt', ''), true), "\n";
echo 'afterClear=', var_export($zip2->getCommentName('a.txt'), true), "\n";
$zip2->close();
@unlink($path);
?>
--EXPECT--
methods=111111
setName=true
setIdx=true
setMiss=false
setArch=true
getName='entry-a'
getIdx='entry-b'
getArch='archive-note'
getB='entry-b'
close=true
reopen name='entry-a'
reopen idx='entry-b'
reopen arch='archive-note'
clearName=true
afterClear=''

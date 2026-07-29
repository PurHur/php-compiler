--TEST--
ZipArchive index/mutation APIs — statIndex/locateName/getFromIndex/delete/rename/addEmptyDir/getStream (#19880)
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
$methods = [
    'statIndex', 'locateName', 'getFromIndex', 'getNameIndex',
    'deleteName', 'deleteIndex', 'addEmptyDir', 'renameName', 'renameIndex', 'getStream',
];
$bits = '';
foreach ($methods as $m) {
    $bits .= method_exists('ZipArchive', $m) ? '1' : '0';
}
echo "methods=$bits\n";

$path = sys_get_temp_dir() . '/phpc_zip_idx_' . getmypid() . '.zip';
@unlink($path);

$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
$zip->addFromString('b.txt', 'world');
echo 'locate=', var_export($zip->locateName('a.txt'), true), "\n";
echo 'locateMiss=', var_export($zip->locateName('nope'), true), "\n";
$st = $zip->statIndex(0);
echo 'stat0=', $st['name'], ':', $st['size'], "\n";
echo 'from0=', $zip->getFromIndex(0), "\n";
echo 'from0len=', $zip->getFromIndex(0, 2), "\n";
echo 'name1=', $zip->getNameIndex(1), "\n";
echo 'addDir=', var_export($zip->addEmptyDir('dir'), true), "\n";
$dirIdx = $zip->locateName('dir/');
echo 'dirIdx=', var_export($dirIdx, true), "\n";
echo 'dirName=', $zip->getNameIndex($dirIdx), "\n";
echo 'rename=', var_export($zip->renameName('b.txt', 'c.txt'), true), "\n";
echo 'locateC=', var_export($zip->locateName('c.txt'), true), "\n";
echo 'renameIdx=', var_export($zip->renameIndex(0, 'aa.txt'), true), "\n";
echo 'name0=', $zip->getNameIndex(0), "\n";
$stream = $zip->getStream('aa.txt');
echo 'stream=', (is_resource($stream) ? 'yes' : 'no'), ' data=', stream_get_contents($stream), "\n";
fclose($stream);
echo 'streamDir=', var_export($zip->getStream('dir/'), true), "\n";
echo 'delName=', var_export($zip->deleteName('c.txt'), true), ' num=', $zip->numFiles, "\n";
echo 'delIdx=', var_export($zip->deleteIndex(0), true), ' num=', $zip->numFiles, "\n";
$zip->close();

$zip2 = new ZipArchive();
$zip2->open($path);
echo 'reopen num=', $zip2->numFiles, ' name0=', $zip2->getNameIndex(0), "\n";
$zip2->close();
@unlink($path);
?>
--EXPECT--
methods=1111111111
locate=0
locateMiss=false
stat0=a.txt:5
from0=hello
from0len=he
name1=b.txt
addDir=true
dirIdx=2
dirName=dir/
rename=true
locateC=1
renameIdx=true
name0=aa.txt
stream=yes data=hello
streamDir=false
delName=true num=2
delIdx=true num=1
reopen num=1 name0=dir/

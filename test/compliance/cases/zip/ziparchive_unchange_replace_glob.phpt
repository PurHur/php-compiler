--TEST--
ZipArchive unchange, replaceFile, addGlob, addPattern (#20387)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsZip()) {
    die('skip ZipArchive withheld on reference profile (#18137)');
}
?>
--FILE--
<?php
$methods = [
    'unchangeArchive', 'unchangeAll', 'unchangeName', 'unchangeIndex',
    'replaceFile', 'addGlob', 'addPattern',
];
$bits = '';
foreach ($methods as $m) {
    $bits .= method_exists('ZipArchive', $m) ? '1' : '0';
}
echo "methods=$bits\n";
echo 'LENGTH_TO_END=', (int) ZipArchive::LENGTH_TO_END, "\n";

$base = sys_get_temp_dir() . '/phpc_zip_uc_' . getmypid();
@mkdir($base);
$zipPath = $base . '/a.zip';
$fileA = $base . '/a.txt';
$fileB = $base . '/b.txt';
$fileC = $base . '/c.txt';
file_put_contents($fileA, 'orig-a');
file_put_contents($fileB, 'orig-b');
file_put_contents($fileC, 'extra-c');
@unlink($zipPath);

$zip = new ZipArchive();
// CREATE|OVERWRITE == 9
$zip->open($zipPath, 9);
$zip->addFromString('a.txt', 'orig-a');
$zip->addFromString('b.txt', 'orig-b');
$zip->setArchiveComment('snap-comment');
$zip->close();

$zip = new ZipArchive();
$zip->open($zipPath);
echo 'open size=', $zip->statName('a.txt')['size'], "\n";
$zip->addFromString('a.txt', 'mutated');
echo 'mut size=', $zip->statName('a.txt')['size'], "\n";
echo 'unchangeName=', var_export($zip->unchangeName('a.txt'), true), "\n";
echo 'restored size=', $zip->statName('a.txt')['size'], ' data=', $zip->getFromName('a.txt'), "\n";

$zip->setArchiveComment('changed-comment');
echo 'comment=', var_export($zip->getArchiveComment(), true), "\n";
echo 'unchangeArchive=', var_export($zip->unchangeArchive(), true), "\n";
echo 'commentBack=', var_export($zip->getArchiveComment(), true), "\n";

$zip->addFromString('new.txt', 'brand');
echo 'numAfterAdd=', $zip->numFiles, "\n";
echo 'unchangeAll=', var_export($zip->unchangeAll(), true), "\n";
echo 'numAfterUnchangeAll=', $zip->numFiles, ' hasNew=', var_export($zip->locateName('new.txt'), true), "\n";

file_put_contents($fileA, 'replaced');
echo 'replaceFile=', var_export($zip->replaceFile($fileA, 0), true), "\n";
echo 'replaced=', $zip->getFromIndex(0), "\n";
echo 'unchangeIndex=', var_export($zip->unchangeIndex(0), true), "\n";
echo 'afterUnchangeIdx=', $zip->getFromIndex(0), "\n";

$globPattern = $base . chr(47) . chr(42) . '.txt';
$glob = $zip->addGlob($globPattern);
if (is_array($glob)) {
    sort($glob);
}
echo 'addGlob=', is_array($glob) ? implode(',', $glob) : 'false', ' num=', $zip->numFiles, "\n";
$pat = $zip->addPattern('/^c\\.txt$/', $base);
if (is_array($pat)) {
    sort($pat);
}
echo 'addPattern=', is_array($pat) ? implode(',', $pat) : 'false', "\n";
echo 'fromC=', $zip->getFromName('c.txt'), "\n";

$zip->close();
@unlink($zipPath);
@unlink($fileA);
@unlink($fileB);
@unlink($fileC);
@rmdir($base);
?>
--EXPECT--
methods=1111111
LENGTH_TO_END=0
open size=6
mut size=7
unchangeName=true
restored size=6 data=orig-a
comment='changed-comment'
unchangeArchive=true
commentBack='snap-comment'
numAfterAdd=3
unchangeAll=true
numAfterUnchangeAll=2 hasNew=false
replaceFile=true
replaced=replaced
unchangeIndex=true
afterUnchangeIdx=orig-a
addGlob=a.txt,b.txt,c.txt num=3
addPattern=c.txt
fromC=extra-c

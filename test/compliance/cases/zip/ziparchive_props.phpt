--TEST--
ZipArchive $lastId / $statusSys / $comment properties (#20584)
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
$path = sys_get_temp_dir() . '/phpc_zip_props_' . getmypid() . '.zip';
@unlink($path);

$z = new ZipArchive();
echo 'ctor lastId=', var_export($z->lastId, true), "\n";
echo 'ctor statusSys=', var_export($z->statusSys, true), "\n";
echo 'ctor comment=', var_export($z->comment, true), "\n";

$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$z->open($path, $flags);
echo 'open lastId=', var_export($z->lastId, true), "\n";
echo 'open statusSys=', var_export($z->statusSys, true), "\n";
echo 'open comment=', var_export($z->comment, true), "\n";

$z->addFromString('a.txt', 'hi');
echo 'add0 lastId=', var_export($z->lastId, true), "\n";
$z->addFromString('b.txt', 'yo');
echo 'add1 lastId=', var_export($z->lastId, true), "\n";
$z->addEmptyDir('d');
echo 'addDir lastId=', var_export($z->lastId, true), "\n";

$z->setArchiveComment('hello-comment');
$comment = $z->comment;
$getArch = $z->getArchiveComment();
$statusSys = $z->statusSys;
$lastId = $z->lastId;
$numFiles = $z->numFiles;
$status = $z->status;
echo 'isset comment=', var_export(isset($z->comment), true), "\n";
echo 'isset statusSys=', var_export(isset($z->statusSys), true), "\n";
echo 'isset lastId=', var_export(isset($z->lastId), true), "\n";
echo 'comment=', var_export($comment, true), "\n";
echo 'getArch=', var_export($getArch, true), "\n";
echo 'match=', var_export($comment === $getArch, true), "\n";
echo 'statusSys=', var_export($statusSys, true), "\n";
echo 'status=', var_export($status, true), "\n";
echo 'numFiles=', var_export($numFiles, true), "\n";

$z->close();

$z2 = new ZipArchive();
$z2->open($path);
$reopenComment = $z2->comment;
$reopenGet = $z2->getArchiveComment();
$reopenSys = $z2->statusSys;
echo 'reopen comment=', var_export($reopenComment, true), "\n";
echo 'reopen get=', var_export($reopenGet, true), "\n";
echo 'reopen statusSys=', var_export($reopenSys, true), "\n";
$z2->close();
@unlink($path);
?>
--EXPECT--
ctor lastId=-1
ctor statusSys=0
ctor comment=''
open lastId=-1
open statusSys=0
open comment=''
add0 lastId=0
add1 lastId=1
addDir lastId=2
isset comment=true
isset statusSys=true
isset lastId=true
comment='hello-comment'
getArch='hello-comment'
match=true
statusSys=0
status=0
numFiles=3
reopen comment='hello-comment'
reopen get='hello-comment'
reopen statusSys=0

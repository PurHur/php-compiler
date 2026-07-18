<?php
/**
 * Repro #20584 — ZipArchive::$lastId / $statusSys / $comment (php-src-strict).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20584_ziparchive_props.php
 */
$path = sys_get_temp_dir() . '/phpc_issue_20584_' . getmypid() . '.zip';
@unlink($path);

$z = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$z->open($path, $flags);
$z->addFromString('a.txt', 'hi');
$z->setArchiveComment('hello-comment');

$comment = $z->comment;
$getArch = $z->getArchiveComment();
$statusSys = $z->statusSys;
$lastId = $z->lastId;

$ok = isset($z->comment)
    && isset($z->statusSys)
    && isset($z->lastId)
    && $comment === $getArch
    && $comment === 'hello-comment'
    && is_int($statusSys)
    && is_int($lastId)
    && $lastId === 0;

echo $ok ? "OK\n" : "FAIL\n";
echo 'comment=', var_export($comment, true), "\n";
echo 'statusSys=', var_export($statusSys, true), "\n";
echo 'lastId=', var_export($lastId, true), "\n";

$z->close();
@unlink($path);

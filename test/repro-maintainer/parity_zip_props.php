<?php
/**
 * Maintainer parity probe for #20584 (ZipArchive stub properties).
 */
$path = sys_get_temp_dir() . '/parity_zip_props_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$flags = ZipArchive::CREATE;
$z->open($path, $flags);
$z->addFromString('a.txt', 'hi');
$z->setArchiveComment('hello-comment');
$comment = $z->comment;
$statusSysSet = isset($z->statusSys);
$lastIdSet = isset($z->lastId);
echo 'comment=';
var_export($comment);
echo ' statusSys_isset=';
var_export($statusSysSet);
echo ' lastId_isset=';
var_export($lastIdSet);
echo "\n";
$z->close();
@unlink($path);

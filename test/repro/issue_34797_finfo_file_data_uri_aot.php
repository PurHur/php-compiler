<?php
/**
 * Issue #34797 — finfo_file / finfo::file(data://) matches Zend (peer #34731 / #34789).
 */
$f = new finfo(FILEINFO_MIME_TYPE);
echo 'method:';
var_export($f->file('data://text/plain,hi'));
echo "\nproc:";
var_export(finfo_file(finfo_open(FILEINFO_MIME_TYPE), 'data://text/plain,hi'));
echo "\nbase64:";
var_export($f->file('data://text/plain;base64,'.base64_encode('hi')));
echo "\nfs:";
$p = sys_get_temp_dir().'/phpc_finfo_34797_'.getmypid().'.txt';
file_put_contents($p, 'hello');
var_export($f->file($p));
@unlink($p);
echo "\n";

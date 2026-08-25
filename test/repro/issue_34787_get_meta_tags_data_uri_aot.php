<?php
/**
 * Issue #34787 — get_meta_tags(data://) matches Zend (peer #34731).
 */
echo 'plain:';
var_export(get_meta_tags('data://text/plain,<meta name="a" content="b">'));
echo "\nbase64:";
var_export(get_meta_tags('data://text/plain;base64,'.base64_encode('<meta name="c" content="d">')));
echo "\nfs:";
$f = sys_get_temp_dir().'/phpc_meta_34787_'.getmypid().'.html';
file_put_contents($f, '<meta name="x" content="y">');
var_export(get_meta_tags($f));
@unlink($f);
echo "\n";

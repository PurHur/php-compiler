<?php
/**
 * #34787 — AOT get_meta_tags(data://) must decode like Zend (php_meta_tags.c / peer #34731).
 */
echo 'plain:';
var_export(get_meta_tags('data://text/plain,<meta name="a" content="b">'));
echo "\n";

echo 'base64:';
var_export(get_meta_tags('data://text/plain;base64,' . base64_encode('<meta name="x" content="y">')));
echo "\n";

$tmp = sys_get_temp_dir().'/phpc_gmt_34787_'.getmypid().'.html';
file_put_contents($tmp, '<meta name="fs" content="ok">');
echo 'fs:';
var_export(get_meta_tags($tmp));
echo "\n";
@unlink($tmp);

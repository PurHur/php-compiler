<?php
/**
 * #34797 — AOT finfo_file(data://) must sniff payload (not false from is_readable/libc).
 * Binary base64 payloads are a NestedJIT base64_decode residual (peer file_get_contents).
 */
$fi = finfo_open(FILEINFO_MIME_TYPE);

echo 'plain=';
var_export(finfo_file($fi, 'data://text/plain,hello world'));
echo "\n";

echo 'base64=';
var_export(finfo_file($fi, 'data://text/plain;base64,'.base64_encode('hello world')));
echo "\n";

$tmp = sys_get_temp_dir().'/phpc_finfo_34797_'.getmypid().'.txt';
file_put_contents($tmp, "hello world\n");
echo 'file=';
var_export(finfo_file($fi, $tmp));
echo "\n";
@unlink($tmp);

$obj = new finfo(FILEINFO_MIME_TYPE);
echo 'obj=';
var_export($obj->file('data://text/plain,hello world'));
echo "\n";

finfo_close($fi);

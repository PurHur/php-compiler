<?php
/**
 * #34789 — mime_content_type(data://) must sniff payload (not false from libc open).
 */
echo 'plain=';
var_export(mime_content_type('data://text/plain,hello world'));
echo "\n";

echo 'base64=';
$b64 = base64_encode('<?php echo 1;');
var_export(mime_content_type('data://text/plain;base64,'.$b64));
echo "\n";

$tmp = sys_get_temp_dir().'/phpc_mime_'.getmypid().'.txt';
file_put_contents($tmp, "hello world\n");
echo 'file=';
var_export(mime_content_type($tmp));
echo "\n";
@unlink($tmp);

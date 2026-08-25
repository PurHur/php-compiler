<?php
/**
 * #34772 — AOT file() must decode data:// like Zend (peer #34731 file_get_contents).
 */
echo 'plain:';
print_r(file('data://text/plain,a'."\n".'b'));

echo 'base64:';
print_r(file('data://text/plain;base64,'.base64_encode("x\ny")));

$tmp = sys_get_temp_dir().'/phpc_file_34772_'.getmypid().'.txt';
file_put_contents($tmp, "fs1\nfs2\n");
echo 'fs:';
print_r(file($tmp));
@unlink($tmp);

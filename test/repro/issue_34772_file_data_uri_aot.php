<?php
/**
 * #34772 — AOT file() must decode data:// like Zend (php_data_wrapper.c / peer #34731).
 */
echo "plain:\n";
print_r(file("data://text/plain,a\nb"));

echo "base64:\n";
print_r(file('data://text/plain;base64,YQpi'));

$tmp = sys_get_temp_dir().'/phpc_file_34772_'.getmypid().'.txt';
file_put_contents($tmp, "x\ny\n");
echo "fs:\n";
print_r(file($tmp));
@unlink($tmp);

echo "ignore_nl:\n";
print_r(file("data://text/plain,a\nb", FILE_IGNORE_NEW_LINES));

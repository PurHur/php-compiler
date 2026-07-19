<?php

// #20742 — thin AOT path predicates via StatPathJitHelper (literals; dirname(__FILE__) segfaults on master)
$dir = '/tmp';
$file = '/etc/hostname';
if (!is_file($file)) {
    $file = '/etc/passwd';
}

echo 'is_dir_tmp='.(is_dir($dir) ? 'yes' : 'no')."\n";
echo 'exists_tmp='.(file_exists($dir) ? 'yes' : 'no')."\n";
echo 'is_file_tmp='.(is_file($dir) ? 'yes' : 'no')."\n";
echo 'is_file_host='.(is_file($file) ? 'yes' : 'no')."\n";
echo 'exists_host='.(file_exists($file) ? 'yes' : 'no')."\n";
echo 'is_dir_host='.(is_dir($file) ? 'yes' : 'no')."\n";
echo 'is_readable_host='.(is_readable($file) ? 'yes' : 'no')."\n";
echo 'missing='.(file_exists('/no/such/phpc-statpath-20742') ? 'yes' : 'no')."\n";

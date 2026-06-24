--TEST--
stdlib fputcsv() fields:/separator: named parameters (#11113, ext/standard/file.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$path = sys_get_temp_dir() . '/fputcsv_named_compliance_' . getmypid() . '.csv';
$fp = fopen($path, 'w');
fputcsv($fp, fields: ['a', 'b'], separator: ',');
fclose($fp);
echo file_get_contents($path);
unlink($path);
?>
--EXPECT--
a,b

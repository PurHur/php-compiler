--TEST--
stdlib fwrite() data: named parameter (#11112, ext/standard/streams.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$path = sys_get_temp_dir() . '/fwrite_named_compliance_' . getmypid() . '.txt';
$fp = fopen($path, 'w');
$n = fwrite($fp, data: 'abc');
fclose($fp);
var_export([$n, file_get_contents($path)]);
echo "\n";
unlink($path);
?>
--EXPECT--
array (
  0 => 3,
  1 => 'abc',
)

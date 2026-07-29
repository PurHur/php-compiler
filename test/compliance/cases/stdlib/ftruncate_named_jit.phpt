--TEST--
ftruncate named stream/size under JIT (issue #24534)
--JIT--
--FILE--
<?php
$rf = new ReflectionFunction('ftruncate');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$path = sys_get_temp_dir() . '/phpc_ftruncate_jit_' . getmypid() . '.txt';
$fp = fopen($path, 'w');
fwrite($fp, 'abcdef');
ftruncate(stream: $fp, size: 3);
fclose($fp);
echo filesize($path), "\n";
@unlink($path);
?>
--EXPECT--
stream,size
3

--TEST--
readfile()/fpassthru() route bytes through active output buffer (issue #11128)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_ob_readfile_' . getmypid() . '.txt';
file_put_contents($path, 'payload');
ob_start();
$n = readfile($path);
$buf = ob_get_clean();
echo ($buf === 'payload' && $n === 7) ? 'readfile_ok' : 'readfile_bad', "\n";
$fp = fopen($path, 'r');
ob_start();
$n2 = fpassthru($fp);
$buf2 = ob_get_clean();
fclose($fp);
echo ($buf2 === 'payload' && $n2 === 7) ? 'fpassthru_ok' : 'fpassthru_bad', "\n";
unlink($path);
--EXPECT--
readfile_ok
fpassthru_ok

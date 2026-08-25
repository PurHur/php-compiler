<?php
declare(strict_types=1);

// AOT: stream_get_line must match Zend (libc force peer #33133) — #34835
$p = tempnam(sys_get_temp_dir(), 'sgl34835_');
file_put_contents($p, "hello\nworld");
$fp = fopen($p, 'r');
$line = stream_get_line($fp, 1024, "\n");
fclose($fp);
@unlink($p);
echo ($line === 'hello') ? "OK\n" : ("FAIL: ".var_export($line, true)."\n");

$f2 = fopen('php://memory', 'r+');
fwrite($f2, "ab\ncd");
rewind($f2);
$line2 = stream_get_line($f2, 100, "\n");
echo ($line2 === 'ab') ? "OK_MEM\n" : ("FAIL_MEM: ".var_export($line2, true)."\n");

$f3 = fopen("data://text/plain,xy\nz", 'r');
$line3 = stream_get_line($f3, 100, "\n");
echo ($line3 === 'xy') ? "OK_DATA\n" : ("FAIL_DATA: ".var_export($line3, true)."\n");

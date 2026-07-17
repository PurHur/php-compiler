<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/phpc_sfi_dbg_' . getmypid();
file_put_contents($tmp, 'x');
$i = new SplFileInfo($tmp);
echo (int) method_exists($i, '__debugInfo'), "\n";
$d = $i->__debugInfo();
echo ($d["\0SplFileInfo\0pathName"] ?? '') === $tmp ? "path-ok\n" : "path-bad\n";
echo ($d["\0SplFileInfo\0fileName"] ?? '') === basename($tmp) ? "file-ok\n" : "file-bad\n";

$o = new SplFileObject($tmp, 'r');
echo (int) method_exists($o, '__debugInfo'), "\n";
$d2 = $o->__debugInfo();
echo ($d2["\0SplFileObject\0openMode"] ?? '') === 'r' ? "mode-ok\n" : "mode-bad\n";
echo ($d2["\0SplFileObject\0delimiter"] ?? '') === ',' ? "delim-ok\n" : "delim-bad\n";
echo ($d2["\0SplFileObject\0enclosure"] ?? '') === '"' ? "encl-ok\n" : "encl-bad\n";

@unlink($tmp);

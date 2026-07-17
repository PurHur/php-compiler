<?php
declare(strict_types=1);
$tmp = sys_get_temp_dir() . "/phpc_sfi_dbg_" . getmypid();
file_put_contents($tmp, "x");
$i = new SplFileInfo($tmp);
echo "method_exists=", (int) method_exists($i, "__debugInfo"), "\n";
if (method_exists($i, "__debugInfo")) {
    $d = $i->__debugInfo();
    echo "pathName=", $d["\0SplFileInfo\0pathName"] ?? "missing", "\n";
    echo "fileName=", $d["\0SplFileInfo\0fileName"] ?? "missing", "\n";
}
$o = new SplFileObject($tmp, "r");
echo "sfo_method=", (int) method_exists($o, "__debugInfo"), "\n";
if (method_exists($o, "__debugInfo")) {
    $d = $o->__debugInfo();
    echo "openMode=", $d["\0SplFileObject\0openMode"] ?? "missing", "\n";
    echo "delimiter=", $d["\0SplFileObject\0delimiter"] ?? "missing", "\n";
}
@unlink($tmp);

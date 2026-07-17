<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/phpc_sfi_info_' . getmypid();
file_put_contents($tmp, 'x');
$i = new SplFileInfo($tmp);
foreach (['getFileInfo', 'getPathInfo', 'openFile', 'setFileClass', 'setInfoClass'] as $m) {
    echo $m, ' method_exists=', (int) method_exists($i, $m), "\n";
}
@unlink($tmp);

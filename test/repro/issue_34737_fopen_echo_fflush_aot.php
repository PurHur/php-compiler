<?php
/**
 * AOT: fopen + echo must exit 0 (emitFflushStdout loads FILE* from @stdout) (#34737).
 *
 * @see php-src main/output.c php_output_flush / fflush(stdout)
 * @see php-src main/main.c request shutdown flush
 */
$paths = [
    'memory' => 'php://memory',
    'temp' => 'php://temp',
    'file' => sys_get_temp_dir().'/phpc_34737_'.getmypid().'.txt',
];
foreach ($paths as $label => $path) {
    $mode = ($label === 'file') ? 'w' : 'r+';
    $f = fopen($path, $mode);
    if ($f === false) {
        echo $label."=fopen_fail\n";
        continue;
    }
    echo $label."=ok\n";
    fclose($f);
    if ($label === 'file') {
        @unlink($path);
    }
}

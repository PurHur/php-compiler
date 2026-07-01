<?php

$f = sys_get_temp_dir().'/phpc_chmod_'.uniqid('', true).'.tmp';
touch($f);
try {
    chmod($f, 0644);
    $intMode = decoct(fileperms($f) & 0777);
    chmod($f, '0644');
    $strMode = decoct(fileperms($f) & 0777);
    echo 'int_mode=', $intMode, ' str_mode=', $strMode, "\n";
    if ($intMode !== $strMode) {
        exit(1);
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
} finally {
    @unlink($f);
}

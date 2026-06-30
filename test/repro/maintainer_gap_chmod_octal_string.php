<?php

$f = sys_get_temp_dir().'/phpc_chmod_'.uniqid('', true).'.tmp';
touch($f);
try {
    $ok = chmod($f, '0644');
    $mode = decoct(fileperms($f) & 0777);
    if (!$ok || '644' !== $mode) {
        echo 'fail: ok=', var_export($ok, true), ' mode=', $mode, "\n";
        exit(1);
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
} finally {
    @unlink($f);
}

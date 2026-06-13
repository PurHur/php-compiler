<?php
echo function_exists('gzgets') ? "gzgets: yes\n" : "gzgets: no\n";
if (!function_exists('gzopen')) {
    echo "skip-no-gzopen\n";
    exit(0);
}
$path = sys_get_temp_dir() . '/phpc_gzgets_repro_' . getmypid() . '.gz';
$fp = gzopen($path, 'w9');
if (false === $fp) {
    echo "open-fail\n";
    exit(1);
}
gzwrite($fp, "line1\nline2\n");
gzclose($fp);
$fp = gzopen($path, 'r');
if (false === $fp) {
    echo "reopen-fail\n";
    @unlink($path);
    exit(1);
}
echo gzgets($fp);
echo var_export(gzgets($fp), true), "\n";
echo var_export(gzgets($fp), true), "\n";
gzclose($fp);
@unlink($path);

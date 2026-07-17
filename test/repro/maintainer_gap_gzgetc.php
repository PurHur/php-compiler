<?php
/** Repro for #20017 — gzgetc one char / EOF. */
$path = sys_get_temp_dir() . '/phpc_gzgetc.gz';
$w = gzopen($path, 'w9');
gzwrite($w, 'AB');
gzclose($w);
$r = gzopen($path, 'r');
echo 'c1=', var_export(gzgetc($r), true), "\n";
echo 'c2=', var_export(gzgetc($r), true), "\n";
echo 'c3=', var_export(gzgetc($r), true), "\n";
gzclose($r);
@unlink($path);

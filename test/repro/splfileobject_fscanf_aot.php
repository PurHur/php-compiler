<?php
/**
 * #33382 — AOT SplFileObject::fscanf array mode (peer procedural fscanf / sscanf_array).
 * Avoid var_export(array) — thin AOT lacks Runtime->vm (#26855). Use json_encode.
 */
$path = sys_get_temp_dir().'/phpc_sfo_fscanf_'.getmypid().'.txt';
file_put_contents($path, "42 hello\n");
$f = new SplFileObject($path);
$r = $f->fscanf('%d %s');
echo 'row=', json_encode($r), "\n";
$r2 = $f->fscanf('%d %s');
echo 'eof=', json_encode($r2), "\n";
@unlink($path);

<?php
/**
 * #33382 — AOT SplFileObject::fscanf via live __spl_fd (peer procedural fscanf).
 * Avoid var_export(array) — thin AOT lacks Runtime->vm (#26855). Use json_encode.
 */
$p = sys_get_temp_dir().'/phpc_sfo_33382_'.getmypid().'.txt';
file_put_contents($p, "42 hello\n");
$o = new SplFileObject($p, 'r');
$row = $o->fscanf('%d %s');
echo 'row=', json_encode($row), "\n";
$eof = $o->fscanf('%d %s');
echo 'eof=', json_encode($eof), "\n";
@unlink($p);

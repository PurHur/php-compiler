<?php
/**
 * #33382 — AOT SplFileObject::fscanf via live __spl_fd (peer #33346 / #27663).
 * Thin AOT cannot var_export arrays (#26855) — use json_encode.
 */
$p = sys_get_temp_dir().'/spl_fscanf_'.getmypid().'.txt';
file_put_contents($p, "42 hello\n");
$o = new SplFileObject($p);
$row = $o->fscanf('%d %s');
@unlink($p);
echo 'row=', json_encode($row), "\n";
$eof = $o->fscanf('%d %s');
echo 'eof=', json_encode($eof), "\n";

<?php
// Repro #33389 — AOT SplFileObject::fscanf by-ref via live __spl_fd.
$path = sys_get_temp_dir().'/phpc_sfo_fscanf_ref_'.getmypid().'.txt';
file_put_contents($path, "7 x\n");
$f = new SplFileObject($path);
$a = null;
$b = null;
$n = $f->fscanf('%d %s', $a, $b);
echo "n=$n a=$a b=$b\n";
$a2 = null;
$b2 = null;
$n2 = $f->fscanf('%d %s', $a2, $b2);
echo "eof=$n2\n";
@unlink($path);

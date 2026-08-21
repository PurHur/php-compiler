<?php
/**
 * #33389 — AOT SplFileObject::fscanf by-ref via live __spl_fd (peer #33382).
 */
$p = sys_get_temp_dir().'/spl_fscanf_br_'.getmypid().'.txt';
file_put_contents($p, "7 x\n");
$o = new SplFileObject($p);
$n = $o->fscanf('%d %s', $a, $b);
@unlink($p);
echo "$n:$a:$b\n";
$n2 = $o->fscanf('%d %s', $c, $d);
echo 'eof=';
var_export($n2);
echo "\n";

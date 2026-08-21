<?php
/**
 * #33388 — AOT SplFileObject::hasChildren / getChildren (php-src always false / null).
 */
$p = sys_get_temp_dir().'/phpc_sfo_33388_'.getmypid().'.txt';
file_put_contents($p, "x\n");
$o = new SplFileObject($p);
echo 'has=', json_encode($o->hasChildren()), "\n";
echo 'kids=', json_encode($o->getChildren()), "\n";
@unlink($p);

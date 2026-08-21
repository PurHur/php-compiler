<?php
/**
 * #33321 — AOT SplFileObject::getCurrentLine aliases fgets (php-src zim_SplFileObject_getCurrentLine).
 */
$p = sys_get_temp_dir().'/phpc_sfo_gcl_33321_'.getmypid().'.txt';
file_put_contents($p, "line1\nline2\n");
$o = new SplFileObject($p);
echo 'gcl='.json_encode($o->getCurrentLine())."\n";
echo 'cur='.json_encode($o->current())."\n";
echo 'gcl2='.json_encode($o->getCurrentLine())."\n";
@unlink($p);

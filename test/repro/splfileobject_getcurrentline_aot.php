<?php
/**
 * #33321 — AOT SplFileObject::getCurrentLine advances like fgets (peer #33319 current/fgets).
 */
$p = sys_get_temp_dir().'/phpc_sfo_gcl_33321_'.getmypid().'.txt';
file_put_contents($p, "line1\nline2\nline3\n");
$o = new SplFileObject($p);
echo 'cur0='.json_encode($o->current())."\n";
echo 'gcl1='.json_encode($o->getCurrentLine())."\n";
echo 'cur1='.json_encode($o->current())."\n";
echo 'fgets='.json_encode($o->fgets())."\n";
echo 'cur2='.json_encode($o->current())."\n";
@unlink($p);

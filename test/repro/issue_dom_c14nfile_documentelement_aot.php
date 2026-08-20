<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$p = sys_get_temp_dir().'/phpc_c14nfile_32964_'.getmypid().'.xml';
@unlink($p);
$n = $d->documentElement->C14NFile($p);
var_dump($n);
echo file_get_contents($p);
echo "\n";
@unlink($p);

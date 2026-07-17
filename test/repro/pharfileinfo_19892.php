<?php
/** Repro #19892 — PharFileInfo class + entry getFilename/isCRCChecked. */
var_export(class_exists('PharFileInfo'));
echo "\n";
var_export(class_exists('Phar'));
echo "\n";
$tmp = sys_get_temp_dir() . '/pharfileinfo_repro_' . getmypid() . '.tar';
@unlink($tmp);
$p = new PharData($tmp);
$p->addFromString('a.txt', 'hi');
$info = $p['a.txt'];
echo get_class($info), "\n";
echo 'fn=', $info->getFilename(), "\n";
echo 'crcChecked=', $info->isCRCChecked() ? '1' : '0', "\n";
echo 'content=', $info->getContent(), "\n";
@unlink($tmp);

<?php
$p = sys_get_temp_dir() . '/t.zip';
@unlink($p);
$z = new ZipArchive();
$z->open($p, ZipArchive::CREATE);
$z->addFromString('foo.txt', 'DATA');
$z->close();
$z2 = new ZipArchive();
$z2->open($p);
echo $z2->getNameIndex(0), "\n";
$z2->close();
@unlink($p);

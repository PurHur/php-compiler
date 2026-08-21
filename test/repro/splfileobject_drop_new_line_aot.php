<?php
/** Issue #33390 — SplFileObject::DROP_NEW_LINE thin AOT vs Zend. */
$path = sys_get_temp_dir() . '/spl_dnl_' . getmypid() . '.txt';
file_put_contents($path, "a\nb\n");
$f = new SplFileObject($path);
$f->setFlags(SplFileObject::DROP_NEW_LINE);
echo 'line=[' . $f->fgets() . "]\n";
echo 'cur=[' . $f->current() . "]\n";
echo 'flags=' . $f->getFlags() . "\n";
@unlink($path);

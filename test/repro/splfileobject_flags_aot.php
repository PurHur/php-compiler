<?php
// Repro #33368 — AOT SplFileObject::setFlags/getFlags via __spl_flags.
$path = sys_get_temp_dir().'/phpc_sfo_flags_'.getmypid().'.txt';
file_put_contents($path, "a,b\nc,d\n");
$f = new SplFileObject($path);
$f->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
echo 'flags='.$f->getFlags()."\n";
$row = $f->fgetcsv();
echo is_array($row) ? ('row='.implode('|', $row)) : 'not-array';
echo "\n";
@unlink($path);

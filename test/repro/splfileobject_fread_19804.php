<?php
$p = tempnam(sys_get_temp_dir(), 'sf');
file_put_contents($p, 'abcdef');
$f = new SplFileObject($p);
echo var_export($f->fread(3), true), "\n";
$t = new SplTempFileObject();
$t->fwrite('hello');
$t->rewind();
echo var_export($t->fread(2), true), "\n";
echo var_export($t->fgetc(), true), "\n";

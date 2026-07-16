<?php
$t = sys_get_temp_dir() . '/di_tostring_' . getmypid();
@mkdir($t);
$d = new DirectoryIterator($t);
$d->rewind();
echo (string) $d->current() === $d->current()->getFilename() ? "ok\n" : 'fail: ' . var_export((string) $d->current(), true) . "\n";
@rmdir($t);

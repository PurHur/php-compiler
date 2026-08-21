<?php
/**
 * #33319 — AOT SplFileObject iterator I/O + eof latch (peer #33318 stream fgets).
 */
$p = sys_get_temp_dir().'/phpc_sfo_fgets_33319_'.getmypid().'.txt';
file_put_contents($p, "line1\nline2\n");
$o = new SplFileObject($p);
echo 'eof0=', $o->eof() ? '1' : '0', "\n";
echo 'fgets1=', var_export($o->fgets(), true), "\n";
echo 'key1=', $o->key(), ' eof1=', $o->eof() ? '1' : '0', ' valid1=', $o->valid() ? '1' : '0', "\n";
echo 'cur1=', var_export($o->current(), true), "\n";
echo 'fgets2=', var_export($o->fgets(), true), "\n";
echo 'key2=', $o->key(), ' eof2=', $o->eof() ? '1' : '0', "\n";
$o->rewind();
echo 'rewound=', var_export($o->current(), true), ' key=', $o->key(), ' eof=', $o->eof() ? '1' : '0', "\n";
@unlink($p);

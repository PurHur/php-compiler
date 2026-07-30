<?php
$p = sys_get_temp_dir() . '/phpc_sfo_seek_oob_' . getmypid() . '.txt';
file_put_contents($p, "a\nb\n");

foreach ([0, 1, 2, 3, 4, 10] as $i) {
    $f = new SplFileObject($p);
    $f->seek($i);
    echo "seek($i) key=", $f->key(), ' valid=', (int) $f->valid(), ' eof=', (int) $f->eof(),
        ' cur=', var_export($f->current(), true), "\n";
}

$p2 = sys_get_temp_dir() . '/phpc_sfo_seek_oob2_' . getmypid() . '.txt';
file_put_contents($p2, "line1\nline2");
$f = new SplFileObject($p2);
$f->seek(10);
echo 'notrail_seek10 key=', $f->key(), ' valid=', (int) $f->valid(), ' eof=', (int) $f->eof(), "\n";

@unlink($p);
@unlink($p2);

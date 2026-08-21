<?php
/** Issue #33364 / #33453 — SplFileObject::seek thin AOT vs Zend (incl. past-EOF key). */
$path = sys_get_temp_dir() . "/spl_seek_" . getmypid() . ".txt";
@unlink($path);
file_put_contents($path, "a\nb\nc\n");
foreach ([0, 1, 2, 3, 4, 10] as $t) {
    $o = new SplFileObject($path);
    $o->seek($t);
    echo "seek($t) key=".$o->key()
        ." valid=".(int)$o->valid()
        ." eof=".(int)$o->eof()
        ."\n";
}
$o = new SplFileObject($path);
$o->seek(1);
echo "seek1_cur=".json_encode($o->current())."\n";
$o = new SplFileObject($path);
$o->seek(10);
echo "seek10_cur=".json_encode($o->current())."\n";
@unlink($path);

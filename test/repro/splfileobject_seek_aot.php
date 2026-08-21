<?php
/** Issue #33364 — SplFileObject::seek thin AOT vs Zend. */
$path = sys_get_temp_dir() . "/spl_seek_" . getmypid() . ".txt";
@unlink($path);
file_put_contents($path, "a\nb\nc\n");
foreach ([0, 1, 2, 3, 4, 10] as $t) {
    $o = new SplFileObject($path);
    $o->seek($t);
    echo "seek($t) key=".$o->key()."\n";
}
$o = new SplFileObject($path);
$o->seek(1);
echo "seek1_cur=".json_encode($o->current())."\n";
@unlink($path);

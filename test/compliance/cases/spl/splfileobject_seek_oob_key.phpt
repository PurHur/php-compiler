--TEST--
SPL SplFileObject::seek() past EOF clamps key to last line index (#25321, php-src-strict)
--FILE--
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
?>
--EXPECT--
seek(0) key=0 valid=1 eof=0 cur='a
'
seek(1) key=1 valid=1 eof=0 cur='b
'
seek(2) key=2 valid=1 eof=0 cur=''
seek(3) key=3 valid=0 eof=1 cur=false
seek(4) key=2 valid=0 eof=1 cur=false
seek(10) key=2 valid=0 eof=1 cur=false
notrail_seek10 key=1 valid=0 eof=1

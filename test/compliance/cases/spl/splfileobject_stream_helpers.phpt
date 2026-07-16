--TEST--
SPL SplFileObject ftell/fstat/flock/fflush/ftruncate/fpassthru (#19664, ext/spl/spl_directory.c)
--FILE--
<?php
$tmp = sys_get_temp_dir() . '/phpc_sfo_stream_' . getmypid() . '.txt';
file_put_contents($tmp, "hello\nworld\n");

$o = new SplFileObject($tmp);
echo 'ftell0=', var_export($o->ftell(), true), "\n";
$o->fgets();
echo 'ftell1=', var_export($o->ftell(), true), "\n";
$st = $o->fstat();
echo 'fstat_size=', var_export($st['size'] ?? null, true), "\n";
echo 'fflush=', var_export($o->fflush(), true), "\n";
echo 'flock=', var_export($o->flock(LOCK_SH), true), "\n";
$o->flock(LOCK_UN);

$o2 = new SplFileObject($tmp, 'r+');
echo 'ftruncate=', var_export($o2->ftruncate(3), true), "\n";
$o2->rewind();
ob_start();
$n = $o2->fpassthru();
$out = ob_get_clean();
echo 'fpassthru_n=', var_export($n, true), "\n";
echo 'fpassthru_out=', var_export($out, true), "\n";

foreach (['ftell', 'fstat', 'flock', 'fflush', 'ftruncate', 'fpassthru'] as $m) {
    echo $m, '_exists=', method_exists(SplFileObject::class, $m) ? 'yes' : 'NO', "\n";
}

unlink($tmp);
?>
--EXPECT--
ftell0=0
ftell1=6
fstat_size=12
fflush=true
flock=true
ftruncate=true
fpassthru_n=3
fpassthru_out='hel'
ftell_exists=yes
fstat_exists=yes
flock_exists=yes
fflush_exists=yes
ftruncate_exists=yes
fpassthru_exists=yes

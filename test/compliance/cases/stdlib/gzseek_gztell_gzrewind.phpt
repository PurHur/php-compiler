--TEST--
stdlib gzseek()/gztell()/gzrewind() on gzip read stream (#14585, ext/zlib/zlib.c)
--FILE--
<?php
foreach (['gzseek', 'gztell', 'gzrewind'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
$path = sys_get_temp_dir() . '/phpc_gzseek_phpt_' . getmypid() . '.gz';
$w = gzopen($path, 'w9');
gzwrite($w, 'hello world');
gzclose($w);
$r = gzopen($path, 'r');
gzseek($r, 6);
echo gztell($r), ':', gzread($r, 5), "\n";
gzrewind($r);
echo gztell($r), ':', gzread($r, 5), "\n";
gzclose($r);
@unlink($path);
--EXPECT--
gzseek=yes
gztell=yes
gzrewind=yes
6:world
0:hello

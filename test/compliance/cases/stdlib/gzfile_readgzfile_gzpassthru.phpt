--TEST--
stdlib readgzfile()/gzfile()/gzpassthru() — gzip file helpers (#4657, ext/zlib/zlib.c)
--FILE--
<?php
foreach (['readgzfile', 'gzfile', 'gzpassthru'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
if (!function_exists('gzopen')) {
    echo "skip-no-gzopen\n";
    exit(0);
}
$path = sys_get_temp_dir() . '/phpc_gzfile_test_' . getmypid() . '.gz';
$fp = gzopen($path, 'w9');
if (false === $fp) {
    echo "open-fail\n";
    exit(1);
}
gzwrite($fp, "line1\nline2\n");
gzclose($fp);
var_export(gzfile($path));
echo "\n";
$n = readgzfile($path);
echo "readgzfile_bytes=", $n, "\n";
$fp = gzopen($path, 'r');
if (false === $fp) {
    echo "reopen-fail\n";
    @unlink($path);
    exit(1);
}
$n2 = gzpassthru($fp);
echo "gzpassthru_bytes=", $n2, "\n";
gzclose($fp);
@unlink($path);
--EXPECT--
readgzfile=yes
gzfile=yes
gzpassthru=yes
array (
  0 => 'line1
',
  1 => 'line2
',
)
line1
line2
readgzfile_bytes=12
line1
line2
gzpassthru_bytes=12

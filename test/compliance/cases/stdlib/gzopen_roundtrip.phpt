--TEST--
stdlib gzopen/gzwrite/gzread/gzclose round-trip (#6168)
--FILE--
<?php
foreach (['gzopen', 'gzwrite', 'gzread', 'gzclose'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
$path = sys_get_temp_dir() . '/phpc_gzopen_test_' . getmypid() . '.gz';
$fp = gzopen($path, 'w9');
if (false === $fp) {
    echo "open-fail\n";
    exit(1);
}
$w = gzwrite($fp, 'hello');
gzclose($fp);
$fp = gzopen($path, 'r');
if (false === $fp) {
    echo "reopen-fail\n";
    @unlink($path);
    exit(1);
}
echo gzread($fp, 10), "\n";
gzclose($fp);
@unlink($path);
--EXPECT--
gzopen=yes
gzwrite=yes
gzread=yes
gzclose=yes
hello

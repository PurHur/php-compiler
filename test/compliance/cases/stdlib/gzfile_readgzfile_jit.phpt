--TEST--
stdlib gzfile/readgzfile/gzpassthru JIT round-trip (#4657 phase 2)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_gzfile_jit_' . getmypid() . '.gz';
$fp = gzopen($path, 'w9');
if (false === $fp) {
    echo "open-fail\n";
    exit(1);
}
gzwrite($fp, "line1\nline2\n");
gzclose($fp);

$lines = gzfile($path);
echo count($lines), "\n";
echo $lines[0], $lines[1];

$fp = gzopen($path, 'r');
if (false === $fp) {
    echo "reopen-fail\n";
    @unlink($path);
    exit(1);
}
echo gzpassthru($fp), "\n";
gzclose($fp);

$bytes = readgzfile($path);
echo $bytes, "\n";
@unlink($path);
--EXPECT--
2
line1
line2
line1
line2
12
line1
line2
12

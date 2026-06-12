--TEST--
AOT: gzopen/gzwrite/gzread/gzclose round-trip (#6168)
--FILE--
<?php
$path = '/tmp/phpc_gzopen_aot_fixture.gz';
$fp = gzopen($path, 'w9');
gzwrite($fp, 'hello');
gzclose($fp);
$fp = gzopen($path, 'r');
echo gzread($fp, 10), "\n";
gzclose($fp);
@unlink($path);
--EXPECT--
hello

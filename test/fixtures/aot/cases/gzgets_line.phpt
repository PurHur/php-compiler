--TEST--
AOT: gzgets() line read from gzip stream (#6290)
--FILE--
<?php
$path = '/tmp/phpc_gzgets_aot_fixture.gz';
$fp = gzopen($path, 'w9');
gzwrite($fp, "line1\nline2\n");
gzclose($fp);
$fp = gzopen($path, 'r');
echo gzgets($fp);
echo var_export(gzgets($fp), true), "\n";
gzclose($fp);
@unlink($path);
--EXPECT--
line1
'line2
'

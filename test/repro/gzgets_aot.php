<?php
$path = '/tmp/phpc_gzgets_aot_test.gz';
$fp = gzopen($path, 'w9');
gzwrite($fp, "line1\nline2\n");
gzclose($fp);
$fp = gzopen($path, 'r');
echo gzgets($fp);
echo var_export(gzgets($fp), true), "\n";
gzclose($fp);
@unlink($path);

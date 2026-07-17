--TEST--
zlib gzgetc() one char then EOF false (ext/zlib, #20017)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_gzgetc_compliance.gz';
$w = gzopen($path, 'w9');
gzwrite($w, 'AB');
gzclose($w);
$r = gzopen($path, 'r');
echo var_export(gzgetc($r), true), "\n";
echo var_export(gzgetc($r), true), "\n";
echo var_export(gzgetc($r), true), "\n";
gzclose($r);
@unlink($path);
try {
    gzgetc(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
'A'
'B'
false
gzgetc(): Argument #1 ($stream) must be of type resource, int given

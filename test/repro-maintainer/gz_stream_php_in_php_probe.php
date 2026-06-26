<?php
/**
 * Issue #8936 repro — gzopen/gzwrite/gzread/gzclose without libz gzFile FFI.
 */
$path = sys_get_temp_dir().'/phpc_gz_probe_'.getmypid().'.gz';
$zp = gzopen($path, 'wb9');
if (false === $zp) {
    fwrite(STDERR, "gzopen write failed\n");
    exit(1);
}
gzwrite($zp, 'hello');
gzclose($zp);
$zr = gzopen($path, 'rb');
if (false === $zr) {
    fwrite(STDERR, "gzopen read failed\n");
    exit(1);
}
echo gzread($zr, 100), "\n";
gzclose($zr);
unlink($path);

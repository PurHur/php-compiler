<?php

declare(strict_types=1);

/**
 * Issue #14596 — gzputs()/gzeof() zlib stream parity (ext/zlib/zlib.c).
 */

if (!function_exists('gzputs') || !function_exists('gzeof')) {
    fwrite(STDERR, "fail: gzputs/gzeof not registered\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'gz14596');
if (false === $tmp) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}

$fp = gzopen($tmp, 'wb');
if (false === $fp) {
    fwrite(STDERR, "fail: gzopen write\n");
    exit(1);
}
if (gzeof($fp)) {
    fwrite(STDERR, "fail: gzeof write stream at start\n");
    exit(1);
}
$written = gzputs($fp, "line1\n");
if (false === $written || 6 !== $written) {
    fwrite(STDERR, "fail: gzputs wrote {$written}\n");
    exit(1);
}
gzputs($fp, "line2\n");
gzclose($fp);

$fp = gzopen($tmp, 'rb');
if (false === $fp) {
    fwrite(STDERR, "fail: gzopen read\n");
    exit(1);
}
if (gzeof($fp)) {
    fwrite(STDERR, "fail: gzeof at start of read stream\n");
    exit(1);
}
$line1 = gzgets($fp);
if ("line1\n" !== $line1) {
    fwrite(STDERR, "fail: line1 got ".var_export($line1, true)."\n");
    exit(1);
}
if (gzeof($fp)) {
    fwrite(STDERR, "fail: gzeof after first line\n");
    exit(1);
}
$line2 = gzgets($fp);
if ("line2\n" !== $line2) {
    fwrite(STDERR, "fail: line2 got ".var_export($line2, true)."\n");
    exit(1);
}
if (!gzeof($fp)) {
    fwrite(STDERR, "fail: gzeof should be true after last line\n");
    exit(1);
}
gzclose($fp);
@unlink($tmp);

echo "ok\n";

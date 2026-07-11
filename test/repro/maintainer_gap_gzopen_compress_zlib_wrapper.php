<?php

declare(strict_types=1);

$fp = gzopen('compress.zlib://data:text/plain,test', 'r');
if (false === $fp) {
    echo "fail-open\n";
    exit(1);
}
echo gzread($fp, 10), "\n";
gzclose($fp);

$gz = gzencode('world', 9);
$b64 = base64_encode($gz);
$fp2 = gzopen('compress.zlib://data:application/octet-stream;base64,'.$b64, 'r');
if (false === $fp2) {
    echo "fail-gzip\n";
    exit(1);
}
echo gzread($fp2, 10), "\n";
gzclose($fp2);

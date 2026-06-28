--TEST--
stdlib gzopen() compress.zlib:// data URI read (ext/zlib/zlib_fopen_wrapper.c, #12817)
--FILE--
<?php
declare(strict_types=1);
$fp = gzopen('compress.zlib://data:text/plain,test', 'r');
if (false === $fp) {
    echo "fail\n";
    exit(1);
}
echo gzread($fp, 10), "\n";
gzclose($fp);
?>
--EXPECT--
test

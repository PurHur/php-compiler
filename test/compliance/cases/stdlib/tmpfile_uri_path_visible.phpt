--TEST--
stdlib tmpfile() meta uri visible via is_file/file_get_contents while open (#24786, ext/standard/file.c)
--FILE--
<?php

declare(strict_types=1);

$h = tmpfile();
if (false === $h) {
    echo "fail\n";
    exit(1);
}
$uri = stream_get_meta_data($h)['uri'];
echo str_starts_with($uri, '/tmp/') ? "uri_tmp\n" : 'uri_bad', "\n";
echo is_file($uri) ? "is_file=yes\n" : "is_file=no\n";
fwrite($h, 'payload');
fflush($h);
$via = @file_get_contents($uri);
echo 'via_path=', false === $via ? 'FAIL' : $via, "\n";
rewind($h);
echo 'via_stream=', stream_get_contents($h), "\n";
fclose($h);
clearstatcache();
echo is_file($uri) ? "after_close=yes\n" : "after_close=no\n";
--EXPECT--
uri_tmp
is_file=yes
via_path=payload
via_stream=payload
after_close=no

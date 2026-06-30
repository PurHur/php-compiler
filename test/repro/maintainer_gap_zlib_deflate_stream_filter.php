<?php
declare(strict_types=1);

$payload = str_repeat('a', 100);
$fp = fopen('php://memory', 'r+');
fwrite($fp, $payload);
rewind($fp);
$filter = stream_filter_append($fp, 'zlib.deflate', STREAM_FILTER_READ);
if (false === $filter) {
    echo "append_fail\n";
    exit(1);
}
$out = stream_get_contents($fp);
$expected = gzdeflate($payload);
if (false === $expected) {
    echo "gzdeflate_fail\n";
    exit(1);
}
$len = strlen($out);
echo "len={$len}\n";
exit($out === $expected ? 0 : 1);

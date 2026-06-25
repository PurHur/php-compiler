--TEST--
stdlib tmpfile() stream_get_meta_data — plainfile unlinked temp path (#11397, ext/standard/streams.c)
--FILE--
<?php

declare(strict_types=1);

$fp = tmpfile();
if (false === $fp) {
    echo "fail\n";
    exit(1);
}
$meta = stream_get_meta_data($fp);
echo $meta['wrapper_type'], "\n";
echo str_starts_with($meta['uri'], '/tmp/') ? "uri_tmp\n" : 'uri_bad', "\n";
echo $meta['mode'], "\n";
echo $meta['stream_type'], "\n";
fwrite($fp, 'probe');
rewind($fp);
echo fread($fp, 5), "\n";
fclose($fp);
--EXPECT--
plainfile
uri_tmp
r+b
STDIO
probe

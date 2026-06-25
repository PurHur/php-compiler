<?php

declare(strict_types=1);

$fp = tmpfile();
if (false === $fp) {
    echo "tmpfile failed\n";
    exit(1);
}
$meta = stream_get_meta_data($fp);
echo $meta['wrapper_type'], "\n";
echo str_starts_with($meta['uri'], '/tmp/') ? "uri_tmp\n" : 'uri:'.$meta['uri']."\n";
echo $meta['mode'], "\n";
echo $meta['stream_type'], "\n";
fwrite($fp, 'probe');
rewind($fp);
echo fread($fp, 5), "\n";
fclose($fp);

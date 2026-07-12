--TEST--
stdlib stream_get_meta_data() seekable offset read after @ silenced call (#18005, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

$m = stream_get_meta_data(tmpfile());
@getimagesizefromstring('not-image');
echo ($m['seekable'] ?? false) ? 'direct=1' : 'direct=0', "\n";
$foreach = false;
foreach ($m as $key => $value) {
    if ('seekable' === $key) {
        $foreach = (bool) $value;
    }
}
echo $foreach ? 'foreach=1' : 'foreach=0', "\n";
--EXPECT--
direct=1
foreach=1

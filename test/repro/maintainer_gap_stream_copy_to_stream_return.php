<?php
$src = fopen('/etc/hosts', 'rb');
$dst = fopen('php://memory', 'w+b');
$n = stream_copy_to_stream($src, $dst);
var_export($n);
echo "\n";
var_export($n > 0);
echo "\n";
rewind($dst);
echo 'dst_len='.strlen(stream_get_contents($dst))."\n";

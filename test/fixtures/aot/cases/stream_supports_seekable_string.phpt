--TEST--
AOT: stream_supports() php-src 8.4 'seekable' string feature (issue #17328)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
echo stream_supports($fp, 'seekable') ? '1' : '0', "\n";
echo stream_supports($fp, 'seek') ? '1' : '0', "\n";
fclose($fp);
--EXPECT--
1
1

--TEST--
stream_supports() string $feature under PHP 8.4 profile (issue #16329, #17328)
--FILE--
<?php
$fp = tmpfile();
echo stream_supports($fp, 'seek') ? '1' : '0', "\n";
echo stream_supports($fp, 'seekable') ? '1' : '0', "\n";
echo stream_supports($fp, 'read') ? '1' : '0', "\n";
echo stream_supports($fp, 'bogus') ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_SUPPORT_SEEK) ? '1' : '0', "\n";
fclose($fp);
--EXPECT--
1
1
1
0
1

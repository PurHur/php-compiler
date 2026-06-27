--TEST--
AOT fputcsv() null/float/bool field coercion (#12447)
--FILE--
<?php
$fp = fopen('php://memory', 'w+');
fputcsv($fp, [null, 1.5, true, 'a', false, 'b']);
rewind($fp);
echo stream_get_contents($fp);
--EXPECT--
,1.5,1,a,,b

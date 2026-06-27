--TEST--
stdlib fputcsv() null/float/bool field coercion (#12447, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'w+');
fputcsv($fp, [null, 1.5, true, 'a', false, 'b']);
rewind($fp);
echo stream_get_contents($fp);
--EXPECT--
,1.5,1,a,,b

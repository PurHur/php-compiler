--TEST--
AOT fputcsv() null $fields TypeError (#19214, ext/standard/formatted_io.c)
--FILE--
<?php
$fp = fopen('php://memory', 'w+');
fputcsv($fp, null);
--EXPECT--
--EXPECT_EXIT--
134

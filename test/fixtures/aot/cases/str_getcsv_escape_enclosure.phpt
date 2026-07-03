--TEST--
AOT: str_getcsv() escape=enclosure doubled-quote unescaping (#9303, ext/standard/file.c)
--FILE--
<?php
$row = str_getcsv('a,"b""c",d', ',', '"', '"');
echo $row[0], '|', $row[1], '|', $row[2], "\n";
--EXPECT--
a|b"c|d

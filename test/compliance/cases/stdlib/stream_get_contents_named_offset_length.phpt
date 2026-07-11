--TEST--
stdlib stream_get_contents() offset:/length: named parameters (#16382, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
rewind($fp);
$positional = stream_get_contents($fp, -1, 1);
rewind($fp);
$namedOffset = stream_get_contents($fp, offset: 1);
rewind($fp);
$namedBoth = stream_get_contents($fp, length: 3, offset: 1);
fclose($fp);
echo 'positional=', var_export($positional, true), "\n";
echo 'named_offset=', var_export($namedOffset, true), "\n";
echo 'named_both=', var_export($namedBoth, true), "\n";
--EXPECT--
positional='ello'
named_offset='ello'
named_both='ell'

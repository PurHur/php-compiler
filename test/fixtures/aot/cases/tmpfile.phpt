--TEST--
AOT: tmpfile() read/write via __compiler_tmpfile (#3228)
--FILE--
<?php
$h = tmpfile();
$w = fwrite($h, "aot");
rewind($h);
$data = fread($h, 3);
fclose($h);
echo "aot" === $data ? "ok\n" : "fail\n";
--EXPECT--
ok

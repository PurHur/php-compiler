--TEST--
stdlib lzf_compress/lzf_decompress JIT round-trip (#6384 phase 2, ext/lzf/lzf.c)
--FILE--
<?php
$s = str_repeat('abc', 100);
$c = lzf_compress($s);
echo is_string($c) ? '1' : '0';
echo lzf_decompress($c) === $s ? '1' : '0';
echo "\n";
?>
--EXPECT--
11

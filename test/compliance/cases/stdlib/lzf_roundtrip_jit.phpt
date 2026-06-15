--TEST--
stdlib lzf_compress/lzf_decompress JIT round-trip via LzfJitHelper (#6384, #8805, ext/lzf/lzf.c)
--JIT--
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

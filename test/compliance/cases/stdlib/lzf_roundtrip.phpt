--TEST--
stdlib lzf_compress/lzf_decompress round-trip via VmLzfCore PHP (#6384, #8805, ext/lzf/lzf.c)
--FILE--
<?php
$s = str_repeat('abc', 100);
$c = lzf_compress($s);
echo is_string($c) ? '1' : '0';
echo lzf_decompress($c) === $s ? '1' : '0';
echo function_exists('lzf_compress') ? '1' : '0';
echo function_exists('lzf_decompress') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1111

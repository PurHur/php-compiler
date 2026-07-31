--TEST--
stdlib extension_loaded('zstd'|'lzf') false without host PECL (#25287)
--FILE--
<?php
declare(strict_types=1);

echo 'zstd_loaded=', (int) extension_loaded('zstd'), "\n";
echo 'zstd_in_list=', (int) in_array('zstd', get_loaded_extensions(), true), "\n";
echo 'zstd_funcs=', (int) (false !== get_extension_funcs('zstd')), "\n";
echo 'zstd_compress=', (int) function_exists('zstd_compress'), "\n";
echo 'lzf_loaded=', (int) extension_loaded('lzf'), "\n";
echo 'lzf_in_list=', (int) in_array('lzf', get_loaded_extensions(), true), "\n";
echo 'lzf_funcs=', (int) (false !== get_extension_funcs('lzf')), "\n";
echo 'lzf_compress=', (int) function_exists('lzf_compress'), "\n";
?>
--EXPECT--
zstd_loaded=0
zstd_in_list=0
zstd_funcs=0
zstd_compress=0
lzf_loaded=0
lzf_in_list=0
lzf_funcs=0
lzf_compress=0

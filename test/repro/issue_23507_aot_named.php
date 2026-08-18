<?php
/**
 * #23507 AOT — named Zend stub args (no Reflection).
 * php-src ext/standard/math.c PHP_FUNCTION(base_convert),
 * ext/standard/string.c PHP_FUNCTION(addcslashes),
 * ext/hash/hash.c PHP_FUNCTION(hash_file).
 */
echo base_convert(num: 'a', from_base: 16, to_base: 10), "\n";
echo addcslashes(string: 'a.b', characters: '.'), "\n";
$p = sys_get_temp_dir().'/hf23507-aot-'.getmypid().'.txt';
file_put_contents($p, 'abc');
echo hash_file(algo: 'sha256', filename: $p), "\n";
unlink($p);

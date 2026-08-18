--TEST--
base_convert/addcslashes/hash_file named Zend stub args (JIT, #23507)
--FILE--
<?php
echo base_convert(num: 'a', from_base: 16, to_base: 10), "\n";
echo addcslashes(string: 'a.b', characters: '.'), "\n";
$p = sys_get_temp_dir().'/hf23507-jit-'.getmypid().'.txt';
file_put_contents($p, 'abc');
echo hash_file(algo: 'sha256', filename: $p), "\n";
unlink($p);
--EXPECT--
10
a\.b
ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad

--TEST--
stdlib hash_algos() — full ext/hash registry (#11463, ext/hash/hash.c)
--FILE--
<?php
$algos = hash_algos();
echo 'count=', count($algos), "\n";
echo 'sha512=', in_array('sha512', $algos, true) ? 'yes' : 'no', "\n";
echo 'crc32c=', in_array('crc32c', $algos, true) ? 'yes' : 'no', "\n";
echo 'md5=', in_array('md5', $algos, true) ? 'yes' : 'no', "\n";
--EXPECT--
count=60
sha512=yes
crc32c=yes
md5=yes

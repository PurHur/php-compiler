--TEST--
stdlib hash() non-crypto digests crc32/crc32b/adler32/fnv* (#4644)
--FILE--
<?php
foreach (['crc32b', 'crc32', 'adler32', 'fnv132', 'fnv1a32'] as $algo) {
    echo $algo, '=', hash($algo, 'abc'), "\n";
}
echo hash('md5', 'abc'), "\n";
?>
--EXPECT--
crc32b=352441c2
crc32=73bb8c64
adler32=024d0127
fnv132=439c2f4b
fnv1a32=1a47e90b
900150983cd24fb0d6963f7d28e17f72

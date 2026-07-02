--TEST--
stdlib mhash() compatibility — MD5 digest and S2K keygen (ext/hash/hash.c, #14975)
--FILE--
<?php
foreach ([
    'mhash',
    'mhash_count',
    'mhash_get_hash_name',
    'mhash_get_block_size',
    'mhash_keygen_s2k',
] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
echo 'count=', mhash_count(), "\n";
echo 'name=', mhash_get_hash_name(MHASH_MD5), "\n";
echo 'block=', mhash_get_block_size(MHASH_MD5), "\n";
echo 'hex=', bin2hex(mhash(MHASH_MD5, 'hello')), "\n";
echo 's2k=', bin2hex(mhash_keygen_s2k(MHASH_MD5, 'pass', 'salt', 16)), "\n";
echo 'invalid=', (int) (false === mhash(999, 'x')), "\n";
--EXPECT--
mhash=1
mhash_count=1
mhash_get_hash_name=1
mhash_get_block_size=1
mhash_keygen_s2k=1
count=41
name=MD5
block=16
hex=5d41402abc4b2a76b9719d911017c592
s2k=aa7d368c208d9a890bdacc9604053e8c
invalid=1

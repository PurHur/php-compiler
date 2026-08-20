<?php

declare(strict_types=1);

echo 'count=', mhash_count(), '|';
echo 'name=', mhash_get_hash_name(MHASH_MD5), '|';
echo 'block=', mhash_get_block_size(MHASH_MD5), '|';
echo 'hex=', bin2hex(mhash(MHASH_MD5, 'hello')), '|';
echo 's2k=', bin2hex(mhash_keygen_s2k(MHASH_MD5, 'pass', 'salt', 16)), '|';
echo 'invalid=', (int) (false === mhash(999, 'x'));
echo "\n";

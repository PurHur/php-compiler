<?php
declare(strict_types=1);

$md5 = md5(null);
$sha1 = sha1(null);
$ok = 'd41d8cd98f00b204e9800998ecf8427e' === $md5
    && 'da39a3ee5e6b4b0d3255bfef95601890afd80709' === $sha1;
if (!$ok) {
    fwrite(STDERR, "fail: md5(null)={$md5} sha1(null)={$sha1}\n");
    exit(1);
}
echo "ok\n";

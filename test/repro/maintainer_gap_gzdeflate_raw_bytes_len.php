<?php
declare(strict_types=1);

$payload = str_repeat('a', 100);
$raw = gzdeflate($payload);
if (false === $raw) {
    echo "gzdeflate_fail\n";
    exit(1);
}
$refHex = '4b4ca43d0000';
$len = strlen($raw);
$pass = 6 === $len
    && bin2hex($raw) === $refHex
    && $payload === gzinflate($raw);
echo $pass ? "ok len={$len}\n" : "fail len={$len} hex=".bin2hex($raw)."\n";
exit($pass ? 0 : 1);

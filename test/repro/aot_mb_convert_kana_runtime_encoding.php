<?php

declare(strict_types=1);

/**
 * #35193 — mb_convert_kana() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_kana)
 */
function enc(string $e): string
{
    return $e;
}

$s = 'ｱ';
foreach (['UTF-8', 'ASCII', '8BIT', 'utf8', 'binary'] as $name) {
    $e = enc($name);
    $out = mb_convert_kana($s, 'KV', $e);
    echo $name, ' ', bin2hex($out), "\n";
}

$lit = 'UTF-8';
echo 'literal ', bin2hex(mb_convert_kana($s, 'KV', $lit)), "\n";

try {
    $bad = enc('nope');
    echo mb_convert_kana($s, 'KV', $bad);
    echo "no error\n";
} catch (ValueError $ex) {
    echo 'bad_enc=', $ex->getMessage(), "\n";
}

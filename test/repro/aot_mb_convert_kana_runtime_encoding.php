<?php

declare(strict_types=1);

/**
 * #35193 — mb_convert_kana() with runtime encoding under thin AOT (PROFILE=8.4+).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_kana)
 *
 * Done-when compares AOT to VM (ASCII/8BIT share the UTF-8 kana path today).
 */
function enc(string $e): string
{
    return $e;
}

foreach (['UTF-8', 'ASCII', '8BIT', 'utf8', 'binary'] as $name) {
    $e = enc($name);
    $s = mb_convert_kana('ｱ', 'KV', $e);
    echo $name, ' ', bin2hex($s), "\n";
}

$lit = 'UTF-8';
echo 'literal ', bin2hex(mb_convert_kana('ｱ', 'KV', $lit)), "\n";

$e2 = enc('UTF-8');
echo 'with_mode ', bin2hex(mb_convert_kana('ｱｲｳ', 'KV', $e2)), "\n";

try {
    $bad = enc('nope');
    echo mb_convert_kana('ｱ', 'KV', $bad);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}

<?php

declare(strict_types=1);

/**
 * #35199 — mb_trim() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_trim)
 */
function enc(string $e): string
{
    return $e;
}

$s = substr('x a ', 1);
echo 'utf8=[', mb_trim($s, null, enc('UTF-8')), "]\n";
echo 'ascii=[', mb_trim($s, null, enc('ASCII')), "]\n";
echo 'bit=[', mb_trim($s, null, enc('8BIT')), "]\n";
$lit = 'UTF-8';
echo 'literal=[', mb_trim($s, null, $lit), "]\n";
echo 'chars=[', mb_trim($s, ' ', enc('UTF-8')), "]\n";
try {
    echo mb_trim($s, null, enc('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}

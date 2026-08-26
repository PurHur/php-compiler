<?php

declare(strict_types=1);

/**
 * #35216 — mb_encoding_aliases() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_encoding_aliases)
 */
function enc(string $e): string
{
    return $e;
}

echo 'utf8=', implode(',', mb_encoding_aliases(enc('UTF-8'))), "\n";
echo 'ascii=', implode(',', mb_encoding_aliases(enc('ASCII'))), "\n";
echo 'latin1=', implode(',', mb_encoding_aliases(enc('ISO-8859-1'))), "\n";
$lit = 'UTF-8';
echo 'literal=', implode(',', mb_encoding_aliases($lit)), "\n";
echo 'empty=', implode(',', mb_encoding_aliases(enc('ISO-2022-JP'))), "\n";
try {
    echo mb_encoding_aliases(enc('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}

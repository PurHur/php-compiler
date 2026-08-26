<?php

declare(strict_types=1);

/**
 * mb_convert_encoding() with non-foldable runtime encodings under thin AOT
 * (leftover of #34309 / #6251).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_encoding)
 */
function enc(string $e): string
{
    return $e;
}

$s = 'café';
echo 'latin1=', bin2hex(mb_convert_encoding($s, enc('ISO-8859-1'), enc('UTF-8'))), "\n";
$bytes = "\xe9";
echo 'utf8=', bin2hex(mb_convert_encoding($bytes, enc('UTF-8'), enc('ISO-8859-1'))), "\n";
echo 'same=', mb_convert_encoding('hello', enc('UTF-8'), enc('UTF-8')), "\n";
try {
    echo mb_convert_encoding('x', enc('nope'), enc('UTF-8'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_to=', $e->getMessage(), "\n";
}
try {
    echo mb_convert_encoding('x', enc('UTF-8'), enc('nope'));
    echo "no error from\n";
} catch (ValueError $e) {
    echo 'bad_from=', $e->getMessage(), "\n";
}

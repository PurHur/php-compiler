<?php

declare(strict_types=1);

/**
 * #35161 — mb_scrub() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_scrub)
 */
function bad_utf8(): string
{
    return "a\x80b";
}

$enc = 'UTF-8';
echo 'utf8=', bin2hex(mb_scrub(bad_utf8(), $enc)), "\n";
$ascii = 'ASCII';
echo 'ascii=', bin2hex(mb_scrub(bad_utf8(), $ascii)), "\n";
$bit = '8bit';
echo 'bit=', bin2hex(mb_scrub(bad_utf8(), $bit)), "\n";
$ok = 'hello';
$enc2 = 'UTF-8';
echo 'ok=', bin2hex(mb_scrub($ok, $enc2)), "\n";
try {
    $bad = 'nope';
    echo mb_scrub('x', $bad);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}

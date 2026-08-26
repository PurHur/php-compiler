<?php

declare(strict_types=1);

/**
 * #35161 — mb_scrub() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_scrub)
 */
$enc = 'UTF-8';
echo bin2hex(mb_scrub("a\xC0b", $enc)), "\n";
$ascii = 'ASCII';
echo bin2hex(mb_scrub("a\xC0b", $ascii)), "\n";
$bit = '8bit';
echo bin2hex(mb_scrub("a\xC0b", $bit)), "\n";
try {
    $bad = 'nope';
    echo mb_scrub('x', $bad);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}

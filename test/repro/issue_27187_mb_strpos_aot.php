<?php

declare(strict_types=1);

/**
 * Repro for #27187 — AOT mb_strpos() hit + miss (boolean false, not int 0).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strpos)
 */
echo mb_strpos('日本語', '本'), "\n";
echo mb_strpos('abc', 'z') === false ? 'F' : 'hit', "\n";

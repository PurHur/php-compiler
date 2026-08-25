<?php

declare(strict_types=1);

/**
 * #34625 — mb_strlen() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strlen)
 */
$s = 'café';
$e = 'UTF-8';
echo mb_strlen($s, $e), "\n";
$ascii = 'ASCII';
echo mb_strlen('hello', $ascii), "\n";
$eight = '8BIT';
echo mb_strlen("a\xC3\xA9", $eight), "\n";

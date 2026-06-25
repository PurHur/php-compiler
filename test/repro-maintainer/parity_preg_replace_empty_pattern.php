<?php

declare(strict_types=1);

/**
 * Maintainer repro: preg_replace() empty // pattern (#11024, ext/pcre/php_pcre.c).
 */

echo preg_replace('//', 'X', 'abc'), "\n";
echo preg_replace('//u', 'Y', 'ab'), "\n";
echo preg_replace('//', 'X', 'abc', 2), "\n";
echo preg_replace('//', 'X', ''), "\n";

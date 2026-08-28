<?php

declare(strict_types=1);

/**
 * AOT: preg_filter() array + string subjects (#27181).
 * php-src: ext/pcre/php_pcre.c PHP_FUNCTION(preg_filter)
 */
echo json_encode(preg_filter('/a/', 'b', ['a1', 'x', 'aa'])), "\n";
echo preg_filter('/a/', 'b', 'a1'), "\n";

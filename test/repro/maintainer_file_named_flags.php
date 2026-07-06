<?php

declare(strict_types=1);

$path = __FILE__;
$f = 6;
echo 'literal6=', is_array(file($path, 6)) ? 'array' : 'false', "\n";
echo 'var=', is_array(file($path, $f)) ? 'array' : 'false', "\n";
echo 'const=', is_array(file($path, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES)) ? 'array' : 'false', "\n";
echo 'ternary=', (is_array(file($path, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES))
    ? count(file($path, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES))
    : 'false'), "\n";

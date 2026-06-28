<?php

declare(strict_types=1);

/** Issue #13045 — trim()/ltrim()/rtrim() PHP 8.4 characters + mode named params. */

$s = '  a  ';
$trim = trim($s, characters: ' ', mode: StringTrimMode::Both);
$ltrim = ltrim($s, characters: ' ', mode: StringTrimMode::Left);
$rtrim = rtrim($s, characters: ' ', mode: StringTrimMode::Right);
$positional = trim('  a  ', ' ', StringTrimMode::Both);

echo 'trim=', $trim, PHP_EOL;
echo 'ltrim=', $ltrim, PHP_EOL;
echo 'rtrim=', $rtrim, PHP_EOL;

if ('a' !== $trim || 'a  ' !== $ltrim || '  a' !== $rtrim || 'a' !== $positional) {
    exit(1);
}

echo 'ok', PHP_EOL;

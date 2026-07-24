<?php
/** Repro #22947 — BackedEnum::from/tryFrom float coerce + E_DEPRECATED (zend_enum.c). */
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo 'DEP:', $no, ':', $str, "\n";

    return true;
});
enum Num: int { case One = 1; }
enum Suit: string { case Hearts = 'H'; }
echo 'numTry=';
var_export(Num::tryFrom(1.7));
echo "\n";
echo 'numFrom=';
var_export(Num::from(1.0));
echo "\n";
echo 'suitTry=';
var_export(Suit::tryFrom(1.5));
echo "\n";

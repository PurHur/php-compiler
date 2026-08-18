<?php
/**
 * Parity: iconv() ASCII//TRANSLIT must transliterate UTF-8 to ASCII (#32103).
 */
declare(strict_types=1);

echo var_export(iconv('UTF-8', 'ASCII//IGNORE', 'a€b'), true), "\n";
echo var_export(iconv('UTF-8', 'ASCII//TRANSLIT', 'a€b'), true), "\n";

$r = iconv('UTF-8', 'ASCII//TRANSLIT', 'café');
var_export($r);
echo "\n";

$r2 = iconv('UTF-8', 'ASCII//IGNORE', "caf\xC3\xA9");
var_export($r2);
echo "\n";

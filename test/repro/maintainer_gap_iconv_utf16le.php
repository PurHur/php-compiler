<?php
/**
 * Parity: iconv() must convert UTF-8 to UTF-16LE (and back).
 */
declare(strict_types=1);

$le = iconv('UTF-8', 'UTF-16LE', 'a');
var_export(bin2hex($le));
echo "\n";

$back = iconv('UTF-16LE', 'UTF-8', $le);
var_export($back);
echo "\n";

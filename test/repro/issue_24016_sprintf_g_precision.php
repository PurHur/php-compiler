<?php
/**
 * #24016 — sprintf/printf/vsprintf %.Ng significant-digit precision (zend_gcvt).
 * php-src: ext/standard/formatted_print.c / Zend/zend_strtod.c zend_gcvt
 */
$cases = [
    ['%.2g', 1234],
    ['%.3g', 1234],
    ['%.1g', 1234],
    ['%.2g', 12.34],
    ['%.2g', 0.01234],
    ['%.0g', 1234],
    ['%.2G', 1234],
];
foreach ($cases as [$f, $v]) {
    echo $f, ' ', $v, ' => ', sprintf($f, $v), "\n";
}
echo '%.*g 2 1234 => ', sprintf('%.*g', 2, 1234), "\n";
echo 'printf %.2g 1234 => ';
printf('%.2g', 1234);
echo "\n";
echo 'vsprintf %.2g 1234 => ', vsprintf('%.2g', [1234]), "\n";

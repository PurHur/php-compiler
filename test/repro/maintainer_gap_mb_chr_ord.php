<?php
declare(strict_types=1);

echo function_exists('mb_chr') ? "chr_exists\n" : "chr_missing\n";
echo function_exists('mb_ord') ? "ord_exists\n" : "ord_missing\n";
echo mb_chr(0x1F600, 'UTF-8'), "\n";
echo mb_ord('😀', 'UTF-8'), "\n";
echo mb_chr(65), "\n";
echo mb_ord('A'), "\n";
var_export(mb_chr(-1));
echo "\n";
var_export(mb_chr(0x110000));
echo "\n";

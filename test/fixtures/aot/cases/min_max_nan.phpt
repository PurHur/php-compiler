--TEST--
AOT: min()/max() NaN operands (#10776)
--FILE--
<?php
declare(strict_types=1);

$n = NAN;
echo min(1, $n), "\n";
echo is_nan(max(1, $n)) ? 'nan' : 'not-nan', "\n";
echo min([1, $n, 3]), "\n";
echo max([1, $n, 3]), "\n";
--EXPECT--
1
nan
3
3

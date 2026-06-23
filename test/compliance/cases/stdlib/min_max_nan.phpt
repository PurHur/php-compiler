--TEST--
stdlib min()/max() — NaN operands match Zend (ext/standard/array.c #10776)
--FILE--
<?php
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

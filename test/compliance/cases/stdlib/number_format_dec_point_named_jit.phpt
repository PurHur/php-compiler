--TEST--
stdlib number_format() dec_point:/thousands_sep: PHP 8.4 named aliases (JIT/AOT, #10015)
--FILE--
<?php
echo number_format(1234.5, 2, dec_point: ',', thousands_sep: '.'), "\n";
?>
--EXPECT--
1.234,50

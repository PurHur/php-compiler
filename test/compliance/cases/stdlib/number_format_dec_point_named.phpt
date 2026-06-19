--TEST--
stdlib number_format() dec_point:/thousands_sep: unknown named params Error (#10132, ext/standard/number_format.c)
--FILE--
<?php
try {
    number_format(1234.5, 2, dec_point: ',', thousands_sep: '.');
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Unknown named parameter $dec_point

--TEST--
stdlib number_format() high $decimals fractional digits JIT (#18525, ext/standard/number_format.c)
--FILE--
<?php
function check_number_format_high_decimals(): void
{
    echo number_format(1.1, 20), "\n";
    echo number_format(1234.5678, 20), "\n";
    echo number_format(1.5, -1), "\n";
}
check_number_format_high_decimals();
?>
--EXPECT--
1.10000000000000008882
1,234.56780000000003383320
2

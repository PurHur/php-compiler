--TEST--
stdlib number_format() JIT — Z_PARAM_LONG / Z_PARAM_STR coercion (#7443)
--FILE--
<?php
echo number_format(1234.5, '2'), "\n";
echo number_format(1234.5, 2, 0), "\n";
echo number_format(1234.5, 2, '.', 0), "\n";
--EXPECT--
1,234.50
1,234050
10234.50

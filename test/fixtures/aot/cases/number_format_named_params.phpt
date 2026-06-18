--TEST--
AOT number_format() named decimals: (issue #9525)
--FILE--
<?php
echo number_format(1.2345, decimals: 2), "\n";
--EXPECT--
1.23

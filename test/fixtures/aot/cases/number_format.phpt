--TEST--
AOT: number_format() for template output
--FILE--
<?php
echo number_format(1234.5, 2), "\n";
echo number_format(1000), "\n";
--EXPECT--
1,234.50
1,000

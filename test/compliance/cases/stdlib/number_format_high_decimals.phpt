--TEST--
stdlib number_format() >14 decimal places matches snprintf fractional digits (#18525)
--FILE--
<?php
declare(strict_types=1);

echo number_format(1.1, 20), "\n";
echo number_format(1234.5678, 20), "\n";
--EXPECT--
1.10000000000000008882
1,234.56780000000003383320

--TEST--
NumberFormatter::DECIMAL ClassConstFetch seeds for AOT create (#35366)
--FILE--
<?php
echo 'DECIMAL=', NumberFormatter::DECIMAL, "\n";
echo 'CURRENCY=', NumberFormatter::CURRENCY, "\n";
echo 'PERCENT=', NumberFormatter::PERCENT, "\n";
$f = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo $f ? 'ok' : 'bad', "\n";
--EXPECT--
DECIMAL=1
CURRENCY=2
PERCENT=3
ok

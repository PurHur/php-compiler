--TEST--
UConverter::REASON_ILLEGAL ClassConstFetch seeds for thin AOT (#35408)
--FILE--
<?php
echo 'REASON_ILLEGAL=', UConverter::REASON_ILLEGAL, "\n";
echo 'UTF8=', UConverter::UTF8, "\n";
echo 'US_ASCII=', UConverter::US_ASCII, "\n";
--EXPECT--
REASON_ILLEGAL=1
UTF8=4
US_ASCII=26

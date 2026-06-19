--TEST--
PHP 8.4 pipe operator with first-class callable (AOT, #4456, #9750)
--FILE--
<?php
echo "hi" |> strtoupper(...);
echo "\n";
echo "ab" |> strtoupper(...);
echo "\n";
echo "hi" |> strtoupper(...) |> strlen(...);
--EXPECT--
HI
AB
2

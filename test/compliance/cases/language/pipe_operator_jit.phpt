--TEST--
PHP 8.4 pipe operator — JIT path after desugar (issue #4456)
--FILE--
<?php
echo "hi" |> strtoupper(...);
echo "\n";
echo "a" . "b" |> strtoupper(...);
echo "\n";
echo "hi" |> strtoupper(...) |> strlen(...);
--EXPECT--
HI
AB
2

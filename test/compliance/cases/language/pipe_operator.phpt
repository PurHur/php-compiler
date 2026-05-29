--TEST--
PHP 8.4 pipe operator with first-class callable (issue #3243)
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

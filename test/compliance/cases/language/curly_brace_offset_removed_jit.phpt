--TEST--
Language: curly-brace offset removed on PHP 8+ — JIT compile-time fatal (#5313)
--FILE--
<?php
$s = 'abc';
echo $s{1}, "\n";
--EXPECT_EXIT--
255

--TEST--
Language: curly-brace offset removed on PHP 8+ (#5313)
--FILE--
<?php
$s = 'abc';
echo $s{1}, "\n";
--EXPECT_EXIT--
255

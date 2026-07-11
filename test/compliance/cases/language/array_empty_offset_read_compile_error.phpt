--TEST--
Language: empty array offset in read context — compile error (#12303, Zend/zend_language_parser.y)
--FILE--
<?php
$a = [1];
var_dump($a[]);
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot use [] for reading

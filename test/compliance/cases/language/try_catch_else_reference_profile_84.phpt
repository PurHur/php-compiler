--TEST--
Language: try/catch/else rejected on PROFILE=8.4 (#31159, Zend/zend_language_parser.y)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try { echo "t"; } catch (Exception $e) { echo "c"; } else { echo "e"; }
echo "\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Parse error:  syntax error, unexpected token "else" in %s on line %d

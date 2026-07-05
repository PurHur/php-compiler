--TEST--
Language: bare private(set)/protected(set) without read modifier — rejected (#16313, Zend/zend_language_parser.y)
--FILE--
<?php
declare(strict_types=1);
class C {
    private(set) string $p = 'x';
}
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: syntax error, unexpected token ")", expecting variable

--TEST--
Language: bare private(set)/protected(set) without read modifier — parse error (#15446, Zend/zend_language_scanner.l)
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
Fatal error: syntax error, unexpected token ")", expecting variable in %s on line %d
--FILE--
<?php
declare(strict_types=1);
class C {
    protected(set) string $p = 'x';
}
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: syntax error, unexpected token ")", expecting variable in %s on line %d
--FILE--
<?php
declare(strict_types=1);
class C {
    private(set) public string $p = 'x';
}
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: syntax error, unexpected token ")", expecting variable in %s on line %d

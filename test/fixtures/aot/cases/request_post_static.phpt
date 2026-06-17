--TEST--
AOT: static method reads $_REQUEST POST name (#878)
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY=name=PostDev
--FILE--
<?php
declare(strict_types=1);
class C {
    private static function name(): string {
        return (string) ($_REQUEST['name'] ?? '');
    }
    public static function go(): void {
        echo self::name(), "\n";
    }
}
C::go();
--EXPECT--
PostDev
--EXPECT_EXIT--
0

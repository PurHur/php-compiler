--TEST--
AOT: contact guard isset offset max length (#697, #878)
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY=name=PostDev
--FILE--
<?php
class Router
{
    public const MAX = 200;

    public static function valid(): bool
    {
        $name = $_REQUEST['name'] ?? '';
        if ($name == '') {
            return false;
        }
        if (isset($name[self::MAX])) {
            return false;
        }

        return true;
    }
}
echo Router::valid() ? 'ok' : 'bad';
--EXPECT--
ok
--EXPECT_EXIT--
0

--TEST--
AOT: late static binding static::method() (issue #1231)
--FILE--
<?php
class Box {
    public static function size(): int {
        return 3;
    }
    public function doubled(): int {
        return static::size() * 2;
    }
}
echo (new Box())->doubled();
--EXPECT--
6

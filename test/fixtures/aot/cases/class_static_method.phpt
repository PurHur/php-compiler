--TEST--
AOT: user class static method factory (issue #2209)
--FILE--
<?php
class C {
    public static function id(): string {
        return 'ok';
    }
}
echo C::id();
--EXPECT--
ok

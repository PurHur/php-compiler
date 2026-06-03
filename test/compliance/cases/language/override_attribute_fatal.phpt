--TEST--
Language: invalid #[\Override] — compile-time fatal (#4995)
--FILE--
<?php
class Base {
    public function foo(): void {}
}
class Bad extends Base {
    #[\Override]
    public function bar(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255

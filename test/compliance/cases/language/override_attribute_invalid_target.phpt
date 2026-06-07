--TEST--
Language: #[\Override] on class compile-time fatal (#6864)
--FILE--
<?php
class Base {
    public function foo(): void {}
}
#[\Override]
class Child extends Base {
    public function foo(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255

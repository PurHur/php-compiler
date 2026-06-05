--TEST--
Language: invalid #[\Override] on missing parent method — compile-time fatal (issue #6355)
--FILE--
<?php
class Base {}
class Child extends Base {
    #[\Override]
    public function typo(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255

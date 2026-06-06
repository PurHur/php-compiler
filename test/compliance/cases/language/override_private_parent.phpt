--TEST--
Language: #[\Override] on method colliding with private parent — compile-time fatal (#6919)
--FILE--
<?php
class Base {
    private function hidden(): void {}
}
class Child extends Base {
    #[\Override]
    public function hidden(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255

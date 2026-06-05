--TEST--
Language: #[\Override] on trait alias method — compile-time fatal (#6440)
--FILE--
<?php
trait T { public function f(): void {} }
class C { use T { f as g; } }
class D extends C {
    #[\Override]
    public function g(): void {}
}
--EXPECT_EXIT--
255

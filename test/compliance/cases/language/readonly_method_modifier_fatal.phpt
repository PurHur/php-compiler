--TEST--
Language: readonly as method modifier — compile-time fatal (#7183)
--FILE--
<?php
class C {
    readonly public function m(): void {}
}
--EXPECT_EXIT--
255

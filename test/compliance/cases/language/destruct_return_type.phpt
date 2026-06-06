--TEST--
Language: __destruct(): void — compile-time fatal (#6844)
--FILE--
<?php
class C { public function __destruct(): void {} }
--EXPECT_EXIT--
255

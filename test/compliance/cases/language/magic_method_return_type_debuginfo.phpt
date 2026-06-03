--TEST--
Language: __debugInfo(): string — compile-time fatal (#4988)
--FILE--
<?php
class C5 { public function __debugInfo(): string { return 'x'; } }
--EXPECT_EXIT--
255

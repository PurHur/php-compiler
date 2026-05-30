--TEST--
Language: readonly class compile-time inheritance rules (#3551)
--FILE--
<?php
readonly class A {}
class B extends A {}
echo "ok\n";
--EXPECT_EXIT--
255

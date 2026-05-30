--TEST--
Language: readonly class cannot extend non-readonly parent (#3551)
--FILE--
<?php
class C {}
readonly class R extends C {}
echo "ok\n";
--EXPECT_EXIT--
255

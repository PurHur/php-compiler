--TEST--
Language: extend final class compile-time fatal (#3406)
--FILE--
<?php
final class F {}
class C extends F {}
echo "ok\n";
--EXPECT_EXIT--
255

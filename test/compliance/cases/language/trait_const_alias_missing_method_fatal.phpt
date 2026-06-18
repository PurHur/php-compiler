--TEST--
Language: traits — aliasing trait constant via `as` is a fatal compile error (issue #9169)
--ENV--
PHP_COMPILER_PHP=php -n
--FILE--
<?php
trait T { public const X = 1; }
class C {
    use T { T::X as Y; }
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AAn alias was defined for T::X but this method does not exist%A


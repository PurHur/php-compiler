--TEST--
Language: public public(set) is legal same-visibility aviz on PHP 8.4 (#29672; was wrongly fatal #6774)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public public(set) string $x = 'a';
}
echo (new C())->x, "\n";
--EXPECT--
a

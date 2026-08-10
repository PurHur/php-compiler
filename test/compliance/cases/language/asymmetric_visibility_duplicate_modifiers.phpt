--TEST--
Language: duplicate set modifiers private(set) private(set) compile fatal (#6774, zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Dup {
    public private(set) private(set) string $x = 'a';
}
echo "ok\n";
--EXPECTF--
Fatal error: Multiple access type modifiers are not allowed in %s on line %d

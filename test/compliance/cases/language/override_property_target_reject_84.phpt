--TEST--
Language: #[\Override] on property rejected under PROFILE=8.4 — TARGET_METHOD only (#25138)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { public int $x = 1; }
class B extends A {
    #[\Override]
    public int $x = 2;
}
echo "OK\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Override" cannot target property (allowed targets: method)

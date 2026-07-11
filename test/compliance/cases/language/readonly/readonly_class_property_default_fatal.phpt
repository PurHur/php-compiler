--TEST--
Language: readonly class property default compile fatal (#18090, Zend/zend_compile.c)
--FILE--
<?php
readonly class C {
    public int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255

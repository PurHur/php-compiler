--TEST--
Language: readonly class property cannot have default value (#17379, Zend/zend_compile.c)
--FILE--
<?php
readonly class R {
    public int $x = 1;
    public string $name = 'x';
}
echo "ok\n";
--EXPECT_EXIT--
255

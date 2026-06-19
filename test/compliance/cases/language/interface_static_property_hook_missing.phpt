--TEST--
Language: interface static property hooks — missing implementor compile error (#9754, zend_compile.c)
--FILE--
<?php
interface I {
    public static string $p { get; set; }
}
class Bad implements I {}
new Bad;
--EXPECT_EXIT--
255

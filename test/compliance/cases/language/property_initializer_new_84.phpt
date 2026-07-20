--TEST--
Language: typed instance property `new` default rejected under PROFILE=8.4 (#21493, re-#18040, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Logger {}
class S {
    public Logger $l = new Logger();
}
$o = new S();
echo $o->l instanceof Logger ? "ok\n" : "no\n";
?>
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context

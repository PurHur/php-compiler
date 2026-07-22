--TEST--
Language: final plain property `final public string $x` (#22241, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class ParentF {
    final public string $name = 'a';
}
$o = new ParentF;
echo $o->name, "\n";
--EXPECT--
a

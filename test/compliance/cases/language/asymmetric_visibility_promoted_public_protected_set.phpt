--TEST--
Language: promoted public protected(set) unparenthesized — parses and reads (#16161, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public protected(set) string $n = 'ok') {}
}
echo (new D())->n, "\n";
--EXPECT--
ok

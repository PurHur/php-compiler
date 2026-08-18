<?php
/**
 * Maintainer gap #32254: use Foo as self leaks php-parser ParserAbstract stack.
 * Zend: compile fatal "Cannot use Foo as self because 'self' is a special class name" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_use() / zend_is_reserved_class_name().
 */
class Foo {}
use Foo as self;
echo "accepted\n";

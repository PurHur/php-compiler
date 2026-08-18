<?php
/**
 * Maintainer gap #32254: use Foo as parent leaks php-parser ParserAbstract stack.
 * Zend: compile fatal "Cannot use Foo as parent because 'parent' is a special class name" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_use() / zend_is_reserved_class_name().
 */
class Foo {}
use Foo as parent;
echo "accepted\n";

<?php
/**
 * Maintainer gap #32251: class Foo { const class = 1; } is accepted.
 * Zend: compile fatal "A class constant must not be called 'class'; it is reserved for class name fetching" (rc=255).
 * php-src: Zend/zend_compile.c zend_compile_class_const_declaration() / zend_check_const_and_trait_alias_name().
 */
class Foo { const class = 1; }
echo Foo::class, "\n";
echo "accepted\n";

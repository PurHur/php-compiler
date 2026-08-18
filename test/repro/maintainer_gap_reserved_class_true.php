<?php
/**
 * Maintainer gap #32206: class true {} is accepted.
 * Zend: compile fatal "Cannot use 'true' as class name as it is reserved" (rc=255).
 * php-src: Zend/zend_compile.c zend_is_reserved_class_name() / zend_assert_valid_class_name().
 */
class true {}
echo "accepted\n";

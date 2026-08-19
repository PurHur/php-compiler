<?php
/**
 * #32471 — Zend zend_is_true(IS_OBJECT) is true, so boolean not is false.
 * php-src: Zend/zend_operators.c zend_is_true / convert_to_boolean
 * AOT previously aborted: Unknown bool cast from type: __object__*
 */
var_dump(!new stdClass());
$o = new stdClass();
var_dump(!$o);
if ($o) {
    echo "yes\n";
} else {
    echo "no\n";
}
class C32471 {}
var_dump(!new C32471());

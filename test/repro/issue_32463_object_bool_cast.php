<?php
/**
 * #32463 — Zend (bool) on IS_OBJECT: true, no warning (leftover of #32452).
 * php-src: Zend/zend_object_handlers.c zend_std_cast_object_to_type(_IS_BOOL)
 * AOT previously aborted: (bool) cast unsupported operand type in JIT (TYPE_OBJECT).
 */
$o = new stdClass();
var_dump((bool) $o);
var_dump((bool) (new stdClass()));
class EmptyToString {
    public function __toString()
    {
        return '';
    }
}
var_dump((bool) (new EmptyToString()));
var_dump(boolval($o));

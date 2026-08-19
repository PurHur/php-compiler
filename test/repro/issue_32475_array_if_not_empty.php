<?php
/**
 * #32475 — Zend zend_is_true(IS_ARRAY): empty → false, non-empty → true.
 * php-src: Zend/zend_operators.c convert_to_boolean / zend_is_true
 * AOT previously aborted: Not implemented escape operand PHPCfg\Op\Stmt\JumpIf
 */
$full = [1, 2];
if ($full) {
    echo "yes\n";
} else {
    echo "no\n";
}
$empty = [];
if ($empty) {
    echo "yes\n";
} else {
    echo "no\n";
}
var_dump(!$full);
var_dump(empty($full));

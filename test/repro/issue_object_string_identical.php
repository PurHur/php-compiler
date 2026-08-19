<?php
/**
 * #32523 — object vs string/int/bool strict identity.
 * php-src: Zend/zend_operators.c zend_is_identical
 * Unlike types are never identical. Do not reuse zend_compare.
 */
echo ((new stdClass()) === "a") ? "y\n" : "n\n";
echo ((new stdClass()) !== "a") ? "y\n" : "n\n";
echo ("a" === new stdClass()) ? "y\n" : "n\n";
echo ("a" !== new stdClass()) ? "y\n" : "n\n";
echo ((new stdClass()) === 1) ? "y\n" : "n\n";
echo ((new stdClass()) !== true) ? "y\n" : "n\n";
